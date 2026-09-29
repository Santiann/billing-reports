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
 * é o que a especificação pede: edição, pagamento e, no commit seguinte, estorno.
 */
class BillingAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private const AGORA = '2026-06-15 09:30:00';

    private function comoUsuario(string $nome = 'Marina Costa', UserRole $perfil = UserRole::Admin): User
    {
        $user = User::factory()->create(['name' => $nome, 'role' => $perfil]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function billing(): Billing
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

    /** @param array<string, mixed> $changes */
    private function editar(Billing $billing, array $changes)
    {
        return $this->putJson("/api/billings/{$billing->id}", [
            'customer_id' => $billing->customer_id,
            'description' => $billing->description,
            'original_amount' => $billing->original_amount,
            'monthly_interest_rate' => $billing->monthly_interest_rate,
            'issue_date' => $billing->issue_date->toDateString(),
            'due_date' => $billing->due_date->toDateString(),
            ...$changes,
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
        $ordenar = function (array $values) use (&$ordenar): array {
            ksort($values);

            return array_map(fn ($v) => is_array($v) ? $ordenar($v) : $v, $values);
        };

        $this->assertSame($ordenar($esperado), $ordenar($gravado));
    }

    /** @return Collection<int, BillingAudit> */
    private function trilha(Billing $billing): Collection
    {
        return BillingAudit::query()
            ->where('billing_id', $billing->id)
            ->orderBy('id')
            ->get();
    }

    // --- edição -------------------------------------------------------

    public function test_an_edit_records_who_what_and_when(): void
    {
        $this->travelTo(self::AGORA);
        $user = $this->comoUsuario();
        $billing = $this->billing();

        $this->editar($billing, [
            'description' => 'Mensalidade de maio — corrigida',
            'due_date' => '2026-05-20',
        ])->assertOk();

        $trilha = $this->trilha($billing);

        $this->assertCount(1, $trilha);
        $this->assertSame('updated', $trilha[0]->event->value);
        $this->assertSame($user->id, $trilha[0]->user_id);
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
    public function test_only_what_changed_enters_the_trail(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();

        $this->editar($billing, [
            'original_amount' => '1000',
            'monthly_interest_rate' => '0.02',
            'description' => 'Outra descrição',
        ])->assertOk();

        $this->assertSame(['description'], array_keys($this->trilha($billing)[0]->changes));
    }

    public function test_an_edit_that_changes_nothing_records_nothing(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();

        $this->editar($billing, [])->assertOk();

        $this->assertCount(0, $this->trilha($billing));
    }

    // --- pagamento ----------------------------------------------------

    /**
     * O pagamento entra com os valores congelados.
     *
     * É o que o estorno vai precisar: quando a cobrança voltar a pendente e as
     * colunas de pagamento forem limpas, o que foi pago continua registrado
     * aqui.
     */
    public function test_a_payment_enters_the_trail_with_the_frozen_amounts(): void
    {
        $this->travelTo(self::AGORA);
        $user = $this->comoUsuario();
        $billing = $this->billing();

        $this->postJson("/api/billings/{$billing->id}/payment")->assertOk();

        $trilha = $this->trilha($billing);

        $this->assertCount(1, $trilha);
        $this->assertSame('paid', $trilha[0]->event->value);
        $this->assertSame($user->id, $trilha[0]->user_id);
        $this->assertMudancas([
            'status' => ['from' => 'pending', 'to' => 'paid'],
            'payment_date' => ['from' => null, 'to' => '2026-06-15'],
            'paid_amount' => ['from' => null, 'to' => '1020.00'],
            'paid_interest_amount' => ['from' => null, 'to' => '20.00'],
        ], $trilha[0]->changes);
    }

    /** A repetição com a mesma chave não reprocessa, então não registra de novo. */
    public function test_an_idempotent_replay_does_not_duplicate_the_trail(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();

        $key = ['Idempotency-Key' => '0c5e1f7a-2b8d-4e3c-9a61-7d4f2e8b1c05'];

        $this->withHeaders($key)->postJson("/api/billings/{$billing->id}/payment")->assertOk();
        $this->withHeaders($key)->postJson("/api/billings/{$billing->id}/payment")->assertOk();

        $this->assertCount(1, $this->trilha($billing));
    }

    // --- o que não entra ----------------------------------------------

    public function test_a_refused_operation_does_not_enter_the_trail(): void
    {
        $this->travelTo(self::AGORA);
        $billing = $this->billing();

        $this->comoUsuario();

        // A factory registra o pagamento pelo RegisterPayment de produção, e
        // esse pagamento entra na trilha — legitimamente. Por isso a contagem
        // é tomada depois da preparação, e não comparada com zero.
        $paga = Billing::factory()->paid()->create();
        $before = BillingAudit::query()->count();

        // Recusada pela validação: cobrança paga não se edita.
        $this->editar($paga, ['description' => 'Tentativa'])->assertUnprocessable();

        // Recusada pelo perfil.
        $this->comoUsuario('Leitor', UserRole::Viewer);
        $this->postJson("/api/billings/{$billing->id}/payment")->assertForbidden();

        $this->assertSame($before, BillingAudit::query()->count());
    }

    // --- atomicidade --------------------------------------------------

    /**
     * Sem registro na trilha, a alteração não acontece.
     *
     * A falha é simulada no evento do próprio model da trilha, e não com DDL:
     * renomear a tabela no meio do teste encerraria a transação do
     * RefreshDatabase por commit implícito (ver a skill de testes).
     */
    public function test_without_the_trail_the_edit_does_not_happen(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();

        BillingAudit::creating(fn () => throw new RuntimeException('Falha simulada ao gravar a trilha.'));

        $this->editar($billing, ['description' => 'Não pode ficar'])->assertServerError();

        $this->assertSame('Mensalidade de maio', $billing->fresh()->description);
    }

    public function test_without_the_trail_the_payment_does_not_happen(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();

        BillingAudit::creating(fn () => throw new RuntimeException('Falha simulada ao gravar a trilha.'));

        $this->postJson("/api/billings/{$billing->id}/payment")->assertServerError();

        $this->assertSame('pending', $billing->fresh()->status->value);
        $this->assertNull($billing->fresh()->paid_amount);
    }

    // --- imutabilidade ------------------------------------------------

    /** Registro errado na trilha se corrige com outro registro, nunca reescrevendo. */
    public function test_the_trail_cannot_be_altered(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();
        $this->editar($billing, ['description' => 'Corrigida'])->assertOk();

        $this->expectException(LogicException::class);

        $this->trilha($billing)[0]->update(['changes' => []]);
    }

    public function test_the_trail_cannot_be_deleted(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();
        $this->editar($billing, ['description' => 'Corrigida'])->assertOk();

        $this->expectException(LogicException::class);

        $this->trilha($billing)[0]->delete();
    }

    /**
     * Alteração fora de uma requisição — tinker, comando artisan — também
     * entra, sem autor. Ficar de fora seria o buraco mais fácil de usar.
     */
    public function test_a_change_with_no_authenticated_user_is_recorded_without_an_author(): void
    {
        $this->travelTo(self::AGORA);
        $billing = $this->billing();

        $billing->update(['description' => 'Alterada pelo console']);

        $trilha = $this->trilha($billing);

        $this->assertCount(1, $trilha);
        $this->assertNull($trilha[0]->user_id);
    }

    // --- leitura ------------------------------------------------------

    public function test_the_api_reads_the_trail_newest_first(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();

        $this->editar($billing, ['due_date' => '2026-05-20'])->assertOk();
        $this->postJson("/api/billings/{$billing->id}/payment")->assertOk();

        $this->getJson("/api/billings/{$billing->id}/audit")
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
    public function test_no_application_code_changes_a_billing_outside_eloquent(): void
    {
        $padrao = '/(?:DB::table\(\s*[\'"]billings[\'"]\s*\)|Billing::(?:query|where\w*)\s*\()'
            .'[^;]*?->(?:update|delete|forceDelete|increment|decrement|upsert)\s*\(/s';

        $encontrados = [];

        foreach ((new Finder())->files()->in(app_path())->name('*.php') as $file) {
            if (preg_match($padrao, $file->getContents()) === 1) {
                $encontrados[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $encontrados, sprintf(
            "Alteração de cobrança por fora do Eloquent, que não passa pela trilha:\n  %s",
            implode("\n  ", $encontrados),
        ));
    }
}
