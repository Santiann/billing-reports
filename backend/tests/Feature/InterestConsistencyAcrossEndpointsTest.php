<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The brief requires the computed value to be "consistent across every screen
 * e relatórios".
 *
 * `InterestCalculatorTest` proves the calculator's two FACES agree. This one proves the next
 * step: that the three ENDPOINTS exposing the value agree with each other, over HTTP, on the
 * same record.
 *
 * They are different paths on purpose — the single billing computes in PHP, while the listing and
 * the report compute in SQL inside the SELECT. It is precisely because they are different paths
 * that they have to be set against each other.
 */
class InterestConsistencyAcrossEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-06-15 09:30:00';

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function cenarios(): array
    {
        //        [ amount,      rate,     days late ]
        return [
            'within term' => ['1000.00', '0.0200', -10],
            'overdue by 1 day' => ['1000.00', '0.0200', 1],
            'overdue by 45 days' => ['1234.57', '0.0333', 45],
            'overdue by 400 days' => ['987654.31', '0.0250', 400],
            'zero rate' => ['500.00', '0.0000', 90],
            'minimum cent' => ['0.01', '0.1500', 365],
        ];
    }

    #[DataProvider('cenarios')]
    public function test_the_three_endpoints_return_the_same_amount(
        string $amount,
        string $rate,
        int $daysLate,
    ): void {
        $this->travelTo(self::HOJE);
        Sanctum::actingAs(User::factory()->create());

        $billing = $daysLate > 0
            ? Billing::factory()->overdue($daysLate)->create([
                'original_amount' => $amount,
                'monthly_interest_rate' => $rate,
            ])
            : Billing::factory()->create([
                'original_amount' => $amount,
                'monthly_interest_rate' => $rate,
            ]);

        // A single billing: the PHP face.
        $isolada = $this->getJson("/api/billings/{$billing->id}")->assertOk()->json('data');

        // The billings listing: the SQL face, via selectRaw.
        $listagem = $this->getJson('/api/billings')->assertOk()->json('data.0');

        // The report: the SQL face, through another query path.
        $report = $this->getJson('/api/reports/billings')->assertOk()->json('data.0');

        foreach (['updated_amount', 'interest_amount'] as $field) {
            $this->assertSame(
                $isolada[$field],
                $listagem[$field],
                "Cobrança isolada e listagem divergiram em {$field}.",
            );

            $this->assertSame(
                $isolada[$field],
                $report[$field],
                "Cobrança isolada e relatório divergiram em {$field}.",
            );
        }
    }

    public function test_the_report_total_matches_the_sum_of_the_rows(): void
    {
        $this->travelTo(self::HOJE);
        Sanctum::actingAs(User::factory()->create());

        Billing::factory()->count(4)->overdue(30)->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
        ]);

        $response = $this->getJson('/api/reports/billings?per_page=100')->assertOk();

        // The total comes from a separate aggregation query. Summing the displayed rows and
        // comparing is the only way to prove the two queries talk about the same set and the
        // same rule.
        $somaDasLinhas = array_sum(array_map(
            fn (array $row) => (float) $row['updated_amount'],
            $response->json('data'),
        ));

        $this->assertSame(
            number_format($somaDasLinhas, 2, '.', ''),
            $response->json('totals.updated_amount'),
        );
    }

    public function test_a_payment_on_the_exact_due_date_generates_no_interest(): void
    {
        $this->travelTo(self::HOJE);
        Sanctum::actingAs(User::factory()->create());

        $billing = Billing::factory()->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-05-01',
            'due_date' => '2026-06-01',
        ]);

        // The boundary: paying on the due date itself is zero days late.
        $this->postJson("/api/billings/{$billing->id}/payment", [
            'payment_date' => '2026-06-01',
        ])->assertOk();

        $billing->refresh();

        $this->assertSame('0.00', $billing->paid_interest_amount);
        $this->assertSame('1000.00', $billing->paid_amount);
    }
}
