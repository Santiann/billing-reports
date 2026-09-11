<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingStatus;
use App\Models\Billing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-06-15 09:30:00';

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_registro_de_pagamento_exige_autenticacao(): void
    {
        $billing = Billing::factory()->create();

        $this->postJson("/api/billings/{$billing->id}/payment")->assertUnauthorized();
    }

    public function test_registra_pagamento_e_congela_os_juros(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        // Vencida há 30 dias a 2% ao mês: 1000 * 1.02^1 = 1020.00.
        $billing = Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);

        $this->postJson("/api/billings/{$billing->id}/payment")
            ->assertOk()
            ->assertJsonPath('data.status', BillingStatus::Paid->value);

        $billing->refresh();

        $this->assertSame('2026-06-15', $billing->payment_date->toDateString());
        $this->assertSame('20.00', $billing->paid_interest_amount);
        $this->assertSame('1020.00', $billing->paid_amount);
    }

    public function test_cobranca_paga_nao_continua_acumulando_juros(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);

        $this->postJson("/api/billings/{$billing->id}/payment")->assertOk();

        $noPagamento = $this->getJson("/api/billings/{$billing->id}")
            ->assertOk()
            ->json('data');

        // Avançar o relógio seis meses é o que dá sentido ao teste: sem isto
        // ele passaria mesmo se a regra recalculasse juros de cobrança paga.
        $this->travelTo('2026-12-15 09:30:00');

        $seisMesesDepois = $this->getJson("/api/billings/{$billing->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame($noPagamento['updated_amount'], $seisMesesDepois['updated_amount']);
        $this->assertSame($noPagamento['interest_amount'], $seisMesesDepois['interest_amount']);
        $this->assertSame('1020.00', $seisMesesDepois['updated_amount']);
    }

    public function test_juros_sao_calculados_na_data_do_pagamento_e_nao_em_hoje(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);

        // Pagamento retroativo: 15 dias de atraso, não 30.
        $this->postJson("/api/billings/{$billing->id}/payment", [
            'payment_date' => '2026-05-31',
        ])->assertOk();

        $billing->refresh();

        // 1000 * 1.02^(15/30) = 1009.95
        $this->assertSame('1009.95', $billing->paid_amount);
        $this->assertSame('9.95', $billing->paid_interest_amount);
    }

    public function test_cobranca_em_dia_e_paga_sem_juros(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-06-01',
            'due_date' => '2026-06-30',
        ]);

        $this->postJson("/api/billings/{$billing->id}/payment")->assertOk();

        $billing->refresh();

        $this->assertSame('0.00', $billing->paid_interest_amount);
        $this->assertSame('1000.00', $billing->paid_amount);
    }

    public function test_valor_pago_informado_prevalece_sobre_o_calculado(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->overdue(30)->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
        ]);

        // Acordo, desconto: o valor efetivamente recebido pode diferir.
        $this->postJson("/api/billings/{$billing->id}/payment", [
            'paid_amount' => '1000.00',
        ])->assertOk();

        $billing->refresh();

        $this->assertSame('1000.00', $billing->paid_amount);
        // Os juros calculados continuam registrados, mesmo com o desconto.
        $this->assertSame('20.00', $billing->paid_interest_amount);
    }

    public function test_cobranca_ja_paga_nao_pode_ser_paga_de_novo(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->paid()->create();

        $this->postJson("/api/billings/{$billing->id}/payment")->assertUnprocessable();
    }

    public function test_data_de_pagamento_no_futuro_e_rejeitada(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->create();

        $this->postJson("/api/billings/{$billing->id}/payment", [
            'payment_date' => '2026-06-16',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_date');
    }

    public function test_pagamento_anterior_a_emissao_e_rejeitado(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $billing = Billing::factory()->create(['issue_date' => '2026-06-01']);

        $this->postJson("/api/billings/{$billing->id}/payment", [
            'payment_date' => '2026-05-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_date');
    }

    public function test_listagem_traz_valor_atualizado_calculado_em_sql(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);

        $this->getJson('/api/billings')
            ->assertOk()
            ->assertJsonPath('data.0.updated_amount', '1020.00')
            ->assertJsonPath('data.0.interest_amount', '20.00');
    }
}
