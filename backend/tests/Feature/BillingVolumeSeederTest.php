<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingStatus;
use App\Domain\Billing\RegisterPayment;
use App\Domain\Report\BillingReportFilters;
use App\Domain\Report\BillingReportQuery;
use App\Models\Billing;
use Database\Seeders\BillingVolumeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The volume seeder is the only place a paid billing is born without going through the API, and
 * therefore the only place the frozen amounts can drift from the rule without anyone noticing.
 * These tests exist to prevent
 * isso.
 *
 * A small sample on purpose: what gets asserted here is the RULE. The proof of volume is the
 * two-million load, measured outside the suite.
 */
class BillingVolumeSeederTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-06-15 09:30:00';

    private const COBRANCAS = 600;

    /**
     * There is no explicit cleanup here, and that is deliberate: what undoes the 600 billings is
     * RefreshDatabase's rollback.
     *
     * Cleaning up with TRUNCATE would be the obvious route and would cost dearly. TRUNCATE is DDL
     * and performs an implicit commit in MySQL; Laravel notices the test's transaction is gone and
     * marks RefreshDatabaseState::$migrated = false, which triggers a full `migrate:fresh` BEFORE
     * EVERY FOLLOWING TEST. Measured on this base: ~50s per test, against 1.3s with no DDL at all.
     *
     * It is the same reason the seeder does not truncate an already empty table.
     */
    private function semear(): void
    {
        $this->travelTo(self::HOJE);

        (new BillingVolumeSeeder(total: self::COBRANCAS))->run();
    }

    public function test_generates_late_paid_billings_with_frozen_interest(): void
    {
        $this->semear();

        $pagasEmAtraso = Billing::query()
            ->where('status', BillingStatus::Paid)
            ->whereColumn('payment_date', '>', 'due_date')
            ->where('paid_interest_amount', '>', 0)
            ->count();

        $this->assertGreaterThan(
            0,
            $pagasEmAtraso,
            'Nenhuma cobrança paga em atraso: a regra de congelamento fica invisível na tela.',
        );
    }

    /**
     * The test that upholds the brief's requirement: the frozen amount has to come from
     * RegisterPayment, not from a formula rewritten inside the seeder.
     *
     * The proof is to rebuild the same billing as pending, pay it through the production service on
     * the same date, and demand equality down to the cent. A second implementation of the rule
     * inside the seeder would fail here.
     */
    public function test_the_frozen_interest_is_what_recording_the_payment_would_produce(): void
    {
        $this->semear();

        $amostra = Billing::query()
            ->where('status', BillingStatus::Paid)
            ->whereColumn('payment_date', '>', 'due_date')
            ->limit(10)
            ->get();

        $this->assertNotEmpty($amostra, 'Sem cobrança paga em atraso não há o que comparar.');

        foreach ($amostra as $semeada) {
            $refeita = Billing::query()->create([
                'customer_id' => $semeada->customer_id,
                'description' => $semeada->description,
                'original_amount' => $semeada->original_amount,
                'monthly_interest_rate' => $semeada->monthly_interest_rate,
                'issue_date' => $semeada->issue_date,
                'due_date' => $semeada->due_date,
            ]);

            app(RegisterPayment::class)($refeita, $semeada->payment_date);

            $this->assertSame(
                $refeita->paid_interest_amount,
                $semeada->paid_interest_amount,
                "Juros congelados divergem na cobrança {$semeada->id}.",
            );
            $this->assertSame(
                $refeita->paid_amount,
                $semeada->paid_amount,
                "Valor pago diverge na cobrança {$semeada->id}.",
            );
        }
    }

    public function test_paid_within_term_accrues_no_interest(): void
    {
        $this->semear();

        $emDiaComJuros = Billing::query()
            ->where('status', BillingStatus::Paid)
            ->whereColumn('payment_date', '<=', 'due_date')
            ->where('paid_interest_amount', '>', 0)
            ->count();

        $this->assertSame(0, $emDiaComJuros);
    }

    /** A payment with a future date does not exist: nobody paid for what has not happened yet. */
    public function test_no_payment_falls_in_the_future(): void
    {
        $this->semear();

        $noFuturo = Billing::query()
            ->where('payment_date', '>', now()->toDateString())
            ->count();

        $this->assertSame(0, $noFuturo);
    }

    public function test_a_pending_billing_has_no_frozen_column_filled(): void
    {
        $this->semear();

        $pendenteComValorPago = Billing::query()
            ->where('status', BillingStatus::Pending)
            ->where(function ($query) {
                $query->whereNotNull('paid_amount')
                    ->orWhereNotNull('paid_interest_amount')
                    ->orWhereNotNull('payment_date');
            })
            ->count();

        $this->assertSame(0, $pendenteComValorPago);
    }

    /**
     * The block's acceptance criterion, seen from where a reviewer will look: the report filtered
     * by paid has to show interest received, not zero.
     */
    public function test_the_paid_report_shows_the_interest_received(): void
    {
        $this->semear();

        $totals = (new BillingReportQuery())->totals(
            new BillingReportFilters(status: 'paid'),
        );

        $this->assertGreaterThan(0, (float) $totals['paid_amount'], 'Recebido zerado.');
        $this->assertGreaterThan(0, (float) $totals['interest_amount'], 'Juros recebidos zerados.');
    }

    /**
     * A small load emits no DDL — it neither drops indexes nor recreates them.
     *
     * That is what keeps this class safe inside RefreshDatabase: DDL performs an implicit commit,
     * ends the test's transaction and forces every following test in the suite to redo the
     * migrations. The seeder only defers the indexes above a threshold, and this test is what warns
     * if that threshold ever drops to the sample's size.
     */
    public function test_a_small_load_emits_no_ddl(): void
    {
        $ddl = [];

        DB::listen(function ($query) use (&$ddl): void {
            if (preg_match('/^\s*(alter|create|drop)\b/i', $query->sql)) {
                $ddl[] = $query->sql;
            }
        });

        (new BillingVolumeSeeder(total: self::COBRANCAS))->run();

        $this->assertSame([], $ddl, 'A carga pequena emitiu DDL: '.implode(' | ', $ddl));
    }
}
