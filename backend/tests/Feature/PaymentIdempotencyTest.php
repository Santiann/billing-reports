<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingStatus;
use App\Models\Billing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Idempotência no registro de pagamento.
 *
 * O problema é concreto: o usuário clica duas vezes, ou o navegador repete a
 * requisição depois de uma queda de rede. Sem chave, a segunda chamada encontra
 * a cobrança já paga e responde 422 — o que é correto para quem tenta pagar de
 * novo, e é uma mentira para quem só repetiu a mesma operação.
 *
 * Com chave, a segunda chamada devolve o RESULTADO DA PRIMEIRA. A distinção
 * entre "repetiu" e "tentou pagar duas vezes" é o assunto destes testes.
 */
class PaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-06-15 09:30:00';

    private const CHAVE = '6f1b2c4a-9e77-4d2f-9a0a-1c3b5d7e9f11';

    private function actingAsUser(): User
    {
        $usuario = User::factory()->create();
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    private function cobrancaVencida(): Billing
    {
        // Vencida há 30 dias a 2% ao mês: 1000 * 1.02 = 1020,00.
        return Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);
    }

    /** @param array<string, mixed> $corpo */
    private function pagar(Billing $cobranca, ?string $chave, array $corpo = [])
    {
        return $this->withHeaders($chave === null ? [] : ['Idempotency-Key' => $chave])
            ->postJson("/api/billings/{$cobranca->id}/payment", $corpo);
    }

    // --- o caso que motiva tudo ---------------------------------------

    public function test_a_segunda_chamada_com_a_mesma_chave_devolve_o_primeiro_resultado(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $cobranca = $this->cobrancaVencida();

        $primeira = $this->pagar($cobranca, self::CHAVE)->assertOk();
        $segunda = $this->pagar($cobranca, self::CHAVE)->assertOk();

        // Mesmo corpo, byte a byte: é o resultado guardado, não um recálculo.
        $this->assertSame($primeira->json(), $segunda->json());
        $this->assertSame('1020.00', $segunda->json('data.paid_amount'));
    }

    public function test_o_duplo_clique_nao_gera_dois_pagamentos(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $cobranca = $this->cobrancaVencida();

        $this->pagar($cobranca, self::CHAVE)->assertOk();
        $this->pagar($cobranca, self::CHAVE)->assertOk();

        $cobranca->refresh();

        $this->assertSame(BillingStatus::Paid, $cobranca->status);
        $this->assertSame('1020.00', $cobranca->paid_amount);
        $this->assertSame('20.00', $cobranca->paid_interest_amount);
    }

    /**
     * A repetição não pode recalcular: se o segundo pedido reprocessasse, os
     * juros congelariam na data da SEGUNDA chamada.
     */
    public function test_a_repeticao_nao_recalcula_os_juros(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $cobranca = $this->cobrancaVencida();

        $primeira = $this->pagar($cobranca, self::CHAVE)->assertOk();

        // Vinte e três horas depois — dentro da validade da chave, e já no dia
        // seguinte: recalcular daria 31 dias de atraso, R$ 1.020,66.
        $this->travelTo('2026-06-16 08:30:00');
        $segunda = $this->pagar($cobranca, self::CHAVE)->assertOk();

        $this->assertSame('1020.00', $primeira->json('data.paid_amount'));
        $this->assertSame('1020.00', $segunda->json('data.paid_amount'));
        $this->assertSame('2026-06-15', $cobranca->refresh()->payment_date->toDateString());
    }

    // --- o que NÃO muda -----------------------------------------------

    /**
     * Sem chave, o comportamento antigo continua: quem tenta pagar uma cobrança
     * já paga recebe 422. Idempotência é para quem repete a MESMA operação, não
     * para transformar erro em sucesso.
     */
    public function test_sem_chave_a_segunda_tentativa_continua_recusada(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $cobranca = $this->cobrancaVencida();

        $this->pagar($cobranca, null)->assertOk();
        $this->pagar($cobranca, null)->assertStatus(422);
    }

    /** Chave nova sobre cobrança já paga também é 422: a operação é outra. */
    public function test_chave_diferente_sobre_cobranca_paga_e_recusada(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $cobranca = $this->cobrancaVencida();

        $this->pagar($cobranca, self::CHAVE)->assertOk();
        $this->pagar($cobranca, 'outra-chave-completamente-diferente')->assertStatus(422);
    }

    /** O erro também é guardado: repetir uma chamada que falhou repete a falha. */
    public function test_a_resposta_de_erro_tambem_e_repetida(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $cobranca = $this->cobrancaVencida();

        // Data futura é recusada pela validação.
        $corpo = ['payment_date' => '2027-01-01'];

        $primeira = $this->pagar($cobranca, self::CHAVE, $corpo)->assertStatus(422);
        $segunda = $this->pagar($cobranca, self::CHAVE, $corpo)->assertStatus(422);

        $this->assertSame($primeira->json(), $segunda->json());
    }

    // --- uso errado da chave ------------------------------------------

    /**
     * Mesma chave com payload diferente é bug de quem chama, e responder o
     * resultado antigo esconderia o bug. O 422 nomeia o problema.
     */
    public function test_mesma_chave_com_payload_diferente_e_recusada(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $cobranca = $this->cobrancaVencida();

        $this->pagar($cobranca, self::CHAVE, ['paid_amount' => '1000.00'])->assertOk();

        $this->pagar($cobranca, self::CHAVE, ['paid_amount' => '999.00'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains(mb_strtolower($m), 'chave'));
    }

    public function test_a_mesma_chave_em_cobrancas_diferentes_e_recusada(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $this->pagar($this->cobrancaVencida(), self::CHAVE)->assertOk();
        $this->pagar($this->cobrancaVencida(), self::CHAVE)->assertStatus(422);
    }

    /** A chave é de quem a usou: outro usuário com a mesma chave não é repetição. */
    public function test_a_chave_e_por_usuario(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $cobranca = $this->cobrancaVencida();

        $this->pagar($cobranca, self::CHAVE)->assertOk();

        // Outro usuário, mesma chave: não recebe a resposta guardada do
        // primeiro — recebe o 422 de cobrança já paga, que é a verdade.
        $this->actingAsUser();
        $this->pagar($cobranca, self::CHAVE)->assertStatus(422);
    }

    /**
     * Duas chamadas ao mesmo tempo: a segunda encontra a chave reservada e
     * ainda sem resposta. Responder 409 é o que impede as duas de processarem.
     */
    public function test_chamada_concorrente_com_a_mesma_chave_responde_409(): void
    {
        $this->travelTo(self::HOJE);
        $usuario = $this->actingAsUser();
        $cobranca = $this->cobrancaVencida();

        // Simula a primeira requisição ainda em voo: a chave está reservada e
        // a resposta ainda não foi gravada.
        DB::table('idempotency_keys')->insert([
            'user_id' => $usuario->id,
            'key' => self::CHAVE,
            'fingerprint' => hash('sha256', 'qualquer'),
            'response_status' => null,
            'response_body' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->pagar($cobranca, self::CHAVE)->assertStatus(409);
    }

    /** Chave vencida é chave nova: guardar resposta para sempre não é opção. */
    public function test_chave_expirada_nao_repete_a_resposta(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        $cobranca = $this->cobrancaVencida();

        $this->pagar($cobranca, self::CHAVE)->assertOk();

        // Passado o prazo, a chave não vale mais e a cobrança já está paga.
        $this->travelTo('2026-06-17 09:30:00');
        $this->pagar($cobranca, self::CHAVE)->assertStatus(422);
    }
}
