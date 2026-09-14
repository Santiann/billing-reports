<?php

namespace Tests\Feature;

use App\Domain\User\UserRole;
use App\Models\Billing;
use App\Models\BillingAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use LogicException;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Trilha de auditoria das cobranças: quem alterou o quê, e quando.
 *
 * Uma trilha só vale pelo que garante, e são três garantias:
 *
 *   completa  -> toda alteração entra, inclusive a que chega por caminho novo
 *   atômica   -> sem registro na trilha, a alteração não acontece
 *   imutável  -> o que foi registrado não se edita nem se apaga
 *
 * A criação fica de fora de propósito — o README explica por quê. O que entra
 * é o que o enunciado pede: edição, pagamento e, no commit seguinte, estorno.
 */
class BillingAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private const AGORA = '2026-06-15 09:30:00';

    private function comoUsuario(string $nome = 'Marina Costa', UserRole $perfil = UserRole::Admin): User
    {
        $usuario = User::factory()->create(['name' => $nome, 'role' => $perfil]);
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    private function cobranca(): Billing
    {
        // Vencida há 30 dias a 2% ao mês: pagar hoje dá 1.020,00.
        return Billing::factory()->create([
            'description' => 'Mensalidade de maio',
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);
    }

    /** @param array<string, mixed> $mudancas */
    private function editar(Billing $cobranca, array $mudancas)
    {
        return $this->putJson("/api/billings/{$cobranca->id}", [
            'customer_id' => $cobranca->customer_id,
            'description' => $cobranca->description,
            'original_amount' => $cobranca->original_amount,
            'monthly_interest_rate' => $cobranca->monthly_interest_rate,
            'issue_date' => $cobranca->issue_date->toDateString(),
            'due_date' => $cobranca->due_date->toDateString(),
            ...$mudancas,
        ]);
    }

    /**
     * Compara as mudanças gravadas sem depender da ordem das chaves.
     *
     * Coluna JSON do MySQL reordena as chaves por tamanho — `{"from", "to"}`
     * volta como `{"to", "from"}`. A ordem que a API entrega é imposta pelo
     * resource; o que se afirma aqui é o conteúdo. `assertEquals` resolveria a
     * ordem, mas aceitaria `null` igual a `''`, e o `from` nulo do pagamento é
     * justamente o que importa.
     *
     * @param  array<string, mixed>  $esperado
     * @param  array<string, mixed>  $gravado
     */
    private function assertMudancas(array $esperado, array $gravado): void
    {
        $ordenar = function (array $valores) use (&$ordenar): array {
            ksort($valores);

            return array_map(fn ($v) => is_array($v) ? $ordenar($v) : $v, $valores);
        };

        $this->assertSame($ordenar($esperado), $ordenar($gravado));
    }

    /** @return Collection<int, BillingAudit> */
    private function trilha(Billing $cobranca): Collection
    {
        return BillingAudit::query()
            ->where('billing_id', $cobranca->id)
            ->orderBy('id')
            ->get();
    }

    // --- edição -------------------------------------------------------

    public function test_a_edicao_registra_quem_o_que_e_quando(): void
    {
        $this->travelTo(self::AGORA);
        $usuario = $this->comoUsuario();
        $cobranca = $this->cobranca();

        $this->editar($cobranca, [
            'description' => 'Mensalidade de maio — corrigida',
            'due_date' => '2026-05-20',
        ])->assertOk();

        $trilha = $this->trilha($cobranca);

        $this->assertCount(1, $trilha);
        $this->assertSame('updated', $trilha[0]->event->value);
        $this->assertSame($usuario->id, $trilha[0]->user_id);
        $this->assertSame(self::AGORA, $trilha[0]->created_at->toDateTimeString());
        $this->assertMudancas([
            'description' => ['from' => 'Mensalidade de maio', 'to' => 'Mensalidade de maio — corrigida'],
            'due_date' => ['from' => '2026-05-16', 'to' => '2026-05-20'],
        ], $trilha[0]->changes);
    }

    /**
     * Só entra o que mudou de fato.
     *
     * O valor chega como "1000" e está gravado como "1000.00": é o mesmo
     * número, e registrá-lo como alteração encheria a trilha de ruído que
     * esconde a alteração verdadeira.
     */
    public function test_so_o_que_mudou_entra_na_trilha(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $cobranca = $this->cobranca();

        $this->editar($cobranca, [
            'original_amount' => '1000',
            'monthly_interest_rate' => '0.02',
            'description' => 'Outra descrição',
        ])->assertOk();

        $this->assertSame(['description'], array_keys($this->trilha($cobranca)[0]->changes));
    }

    public function test_edicao_que_nao_muda_nada_nao_registra(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $cobranca = $this->cobranca();

        $this->editar($cobranca, [])->assertOk();

        $this->assertCount(0, $this->trilha($cobranca));
    }

    // --- pagamento ----------------------------------------------------

    /**
     * O pagamento entra com os valores congelados.
     *
     * É o que o estorno vai precisar: quando a cobrança voltar a pendente e as
     * colunas de pagamento forem limpas, o que foi pago continua registrado
     * aqui.
     */
    public function test_o_pagamento_entra_na_trilha_com_os_valores_congelados(): void
    {
        $this->travelTo(self::AGORA);
        $usuario = $this->comoUsuario();
        $cobranca = $this->cobranca();

        $this->postJson("/api/billings/{$cobranca->id}/payment")->assertOk();

        $trilha = $this->trilha($cobranca);

        $this->assertCount(1, $trilha);
        $this->assertSame('paid', $trilha[0]->event->value);
        $this->assertSame($usuario->id, $trilha[0]->user_id);
        $this->assertMudancas([
            'status' => ['from' => 'pending', 'to' => 'paid'],
            'payment_date' => ['from' => null, 'to' => '2026-06-15'],
            'paid_amount' => ['from' => null, 'to' => '1020.00'],
            'paid_interest_amount' => ['from' => null, 'to' => '20.00'],
        ], $trilha[0]->changes);
    }

    /** A repetição com a mesma chave não reprocessa, então não registra de novo. */
    public function test_a_repeticao_idempotente_nao_duplica_a_trilha(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $cobranca = $this->cobranca();

        $chave = ['Idempotency-Key' => '0c5e1f7a-2b8d-4e3c-9a61-7d4f2e8b1c05'];

        $this->withHeaders($chave)->postJson("/api/billings/{$cobranca->id}/payment")->assertOk();
        $this->withHeaders($chave)->postJson("/api/billings/{$cobranca->id}/payment")->assertOk();

        $this->assertCount(1, $this->trilha($cobranca));
    }

    // --- o que não entra ----------------------------------------------

    public function test_operacao_recusada_nao_entra_na_trilha(): void
    {
        $this->travelTo(self::AGORA);
        $cobranca = $this->cobranca();

        $this->comoUsuario();

        // A factory registra o pagamento pelo RegisterPayment de produção, e
        // esse pagamento entra na trilha — legitimamente. Por isso a contagem
        // é tomada depois da preparação, e não comparada com zero.
        $paga = Billing::factory()->paid()->create();
        $antes = BillingAudit::query()->count();

        // Recusada pela validação: cobrança paga não se edita.
        $this->editar($paga, ['description' => 'Tentativa'])->assertUnprocessable();

        // Recusada pelo perfil.
        $this->comoUsuario('Leitor', UserRole::Viewer);
        $this->postJson("/api/billings/{$cobranca->id}/payment")->assertForbidden();

        $this->assertSame($antes, BillingAudit::query()->count());
    }

    // --- atomicidade --------------------------------------------------

    /**
     * Sem registro na trilha, a alteração não acontece.
     *
     * A falha é simulada no evento do próprio model da trilha, e não com DDL:
     * renomear a tabela no meio do teste encerraria a transação do
     * RefreshDatabase por commit implícito (ver a skill de testes).
     */
    public function test_sem_trilha_a_edicao_nao_acontece(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $cobranca = $this->cobranca();

        BillingAudit::creating(fn () => throw new RuntimeException('Falha simulada ao gravar a trilha.'));

        $this->editar($cobranca, ['description' => 'Não pode ficar'])->assertServerError();

        $this->assertSame('Mensalidade de maio', $cobranca->fresh()->description);
    }

    public function test_sem_trilha_o_pagamento_nao_acontece(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $cobranca = $this->cobranca();

        BillingAudit::creating(fn () => throw new RuntimeException('Falha simulada ao gravar a trilha.'));

        $this->postJson("/api/billings/{$cobranca->id}/payment")->assertServerError();

        $this->assertSame('pending', $cobranca->fresh()->status->value);
        $this->assertNull($cobranca->fresh()->paid_amount);
    }

    // --- imutabilidade ------------------------------------------------

    /** Registro errado na trilha se corrige com outro registro, nunca reescrevendo. */
    public function test_a_trilha_nao_se_altera(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $cobranca = $this->cobranca();
        $this->editar($cobranca, ['description' => 'Corrigida'])->assertOk();

        $this->expectException(LogicException::class);

        $this->trilha($cobranca)[0]->update(['changes' => []]);
    }

    public function test_a_trilha_nao_se_apaga(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $cobranca = $this->cobranca();
        $this->editar($cobranca, ['description' => 'Corrigida'])->assertOk();

        $this->expectException(LogicException::class);

        $this->trilha($cobranca)[0]->delete();
    }

    /**
     * Alteração fora de uma requisição — tinker, comando artisan — também
     * entra, sem autor. Ficar de fora seria o buraco mais fácil de usar.
     */
    public function test_alteracao_sem_usuario_autenticado_entra_sem_autor(): void
    {
        $this->travelTo(self::AGORA);
        $cobranca = $this->cobranca();

        $cobranca->update(['description' => 'Alterada pelo console']);

        $trilha = $this->trilha($cobranca);

        $this->assertCount(1, $trilha);
        $this->assertNull($trilha[0]->user_id);
    }

    // --- leitura ------------------------------------------------------

    public function test_a_trilha_e_lida_pela_api_da_mais_recente_para_a_mais_antiga(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $cobranca = $this->cobranca();

        $this->editar($cobranca, ['due_date' => '2026-05-20'])->assertOk();
        $this->postJson("/api/billings/{$cobranca->id}/payment")->assertOk();

        $this->getJson("/api/billings/{$cobranca->id}/audit")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event', 'paid')
            ->assertJsonPath('data.0.event_label', 'Pagamento registrado')
            ->assertJsonPath('data.0.user.name', 'Marina Costa')
            ->assertJsonPath('data.0.created_at', now()->toIso8601String())
            ->assertJsonPath('data.0.changes.0', [
                'field' => 'status',
                'label' => 'Status',
                'from' => 'pending',
                'to' => 'paid',
            ])
            ->assertJsonPath('data.1.event', 'updated')
            ->assertJsonPath('data.1.changes.0.label', 'Vencimento');
    }

    // --- porta dos fundos ---------------------------------------------

    /**
     * A trilha é gravada por evento do Eloquent, então consulta crua que
     * altera cobrança passa por fora dela sem aviso.
     *
     * Este teste varre `app/` atrás desse caso. Tem limite, e o limite fica
     * dito: pega a escrita encadeada na mesma instrução — `DB::table('billings')
     * ->update(...)`, `Billing::query()->...->update(...)` — e não pega o
     * construtor guardado numa variável e alterado três linhas depois. Existe
     * para o erro óbvio não passar na revisão, não para substituí-la.
     *
     * O seeder fica fora da varredura: está em `database/`, grava volume de
     * teste, e dois milhões de registros de auditoria não descreveriam nada.
     */
    public function test_nenhum_codigo_da_aplicacao_altera_cobranca_por_fora_do_eloquent(): void
    {
        $padrao = '/(?:DB::table\(\s*[\'"]billings[\'"]\s*\)|Billing::(?:query|where\w*)\s*\()'
            .'[^;]*?->(?:update|delete|forceDelete|increment|decrement|upsert)\s*\(/s';

        $encontrados = [];

        foreach ((new Finder())->files()->in(app_path())->name('*.php') as $arquivo) {
            if (preg_match($padrao, $arquivo->getContents()) === 1) {
                $encontrados[] = $arquivo->getRelativePathname();
            }
        }

        $this->assertSame([], $encontrados, sprintf(
            "Alteração de cobrança por fora do Eloquent, que não passa pela trilha:\n  %s",
            implode("\n  ", $encontrados),
        ));
    }
}
