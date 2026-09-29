<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingReportTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-06-15 09:30:00';

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    // --- protection ---------------------------------------------------

    public function test_report_requires_authentication(): void
    {
        $this->getJson('/api/reports/billings')->assertUnauthorized();
    }

    public function test_report_responds_to_an_authenticated_user(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/reports/billings')->assertOk();
    }

    // --- period and date basis ----------------------------------------

    public function test_period_by_issue_date(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->create(['issue_date' => '2026-03-10', 'due_date' => '2026-04-10']);
        Billing::factory()->create(['issue_date' => '2026-05-20', 'due_date' => '2026-06-20']);

        $response = $this->getJson(
            '/api/reports/billings?date_field=issue_date&start_date=2026-03-01&end_date=2026-03-31',
        )->assertOk();

        $this->assertSame(1, $response->json('totals.count'));
    }

    public function test_period_by_due_date(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->create(['issue_date' => '2026-03-10', 'due_date' => '2026-04-10']);
        Billing::factory()->create(['issue_date' => '2026-05-20', 'due_date' => '2026-06-20']);

        // The same pair of billings, a different date basis, a different scope: that is what
        // choosing the basis has to prove.
        $response = $this->getJson(
            '/api/reports/billings?date_field=due_date&start_date=2026-06-01&end_date=2026-06-30',
        )->assertOk();

        $this->assertSame(1, $response->json('totals.count'));
    }

    public function test_period_by_payment_date_ignores_unpaid_billings(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->paid()->create();
        Billing::factory()->count(3)->create();

        $response = $this->getJson(
            '/api/reports/billings?date_field=payment_date&start_date=2026-01-01&end_date=2026-12-31',
        )->assertOk();

        // An unpaid billing has a null payment_date and falls outside the scope.
        $this->assertSame(1, $response->json('totals.count'));
    }

    public function test_an_invalid_date_basis_is_rejected(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/reports/billings?date_field=created_at')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_field');
    }

    public function test_an_end_date_before_the_start_date_is_rejected(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/reports/billings?start_date=2026-06-30&end_date=2026-06-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
    }

    // --- demais filtros -----------------------------------------------

    public function test_filters_by_customer(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create();
        Billing::factory()->count(2)->for($customer)->create();
        Billing::factory()->count(5)->create();

        $response = $this->getJson("/api/reports/billings?customer_id={$customer->id}")->assertOk();

        $this->assertSame(2, $response->json('totals.count'));
    }

    public function test_filters_by_paid_status(): void
    {
        $this->actingAsUser();
        Billing::factory()->count(3)->paid()->create();
        Billing::factory()->count(4)->create();

        $response = $this->getJson('/api/reports/billings?status=paid')->assertOk();

        $this->assertSame(3, $response->json('totals.count'));
    }

    public function test_filters_by_pending_status(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->count(4)->create();          // pendentes no prazo
        Billing::factory()->count(2)->overdue(30)->create(); // pendentes vencidas
        Billing::factory()->count(3)->paid()->create();

        // `pending` is the stored status and includes the overdue ones, which are pending with
        // the due date in the past. Whoever wants only the overdue ones uses `overdue`.
        $response = $this->getJson('/api/reports/billings?status=pending')->assertOk();

        $this->assertSame(6, $response->json('totals.count'));
    }

    public function test_filters_by_overdue_which_is_a_derived_condition(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->count(2)->overdue(30)->create();
        Billing::factory()->count(3)->create();       // pendentes, no prazo
        Billing::factory()->count(4)->paidLate()->create();  // pagas, não contam

        $response = $this->getJson('/api/reports/billings?status=overdue')->assertOk();

        // "Overdue" does not exist as a stored value: it is pending with the due date in the
        // past, resolved in SQL.
        $this->assertSame(2, $response->json('totals.count'));
    }

    // --- sorting ------------------------------------------------------

    public function test_sorts_by_updated_amount(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        // A smaller original amount, but far more overdue: the updated amount flips the order.
        // Sorting this way is only possible because the calculation exists in SQL.
        Billing::factory()->overdue(5)->create([
            'original_amount' => '1000.00', 'monthly_interest_rate' => '0.0200',
        ]);
        Billing::factory()->overdue(720)->create([
            'original_amount' => '900.00', 'monthly_interest_rate' => '0.1000',
        ]);

        $values = $this->getJson('/api/reports/billings?sort=updated_amount&direction=desc')
            ->assertOk()
            ->json('data.*.updated_amount');

        $this->assertGreaterThan((float) $values[1], (float) $values[0]);
        $this->assertGreaterThan(900.0, (float) $values[0]);
    }

    public function test_sorting_by_an_arbitrary_column_is_rejected(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/reports/billings?sort=(select 1)')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');
    }

    // --- totalizadores ------------------------------------------------

    public function test_totals_cover_the_whole_set_and_not_the_page(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        // 25 billings of R$ 100.00 with no interest, on a page of 10.
        Billing::factory()->count(25)->create([
            'original_amount' => '100.00',
            'monthly_interest_rate' => '0.0000',
        ]);

        $response = $this->getJson('/api/reports/billings?per_page=10')->assertOk();

        $this->assertCount(10, $response->json('data'), 'A página deveria trazer 10.');

        // The easy mistake is to sum the page: that would give 1000.00.
        $this->assertSame(25, $response->json('totals.count'));
        $this->assertSame('2500.00', $response->json('totals.original_amount'));
        $this->assertSame('2500.00', $response->json('totals.updated_amount'));
    }

    public function test_totals_respect_the_filter(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $customer = Customer::factory()->create();
        Billing::factory()->count(3)->for($customer)->create([
            'original_amount' => '100.00', 'monthly_interest_rate' => '0.0000',
        ]);
        Billing::factory()->count(7)->create([
            'original_amount' => '999.00', 'monthly_interest_rate' => '0.0000',
        ]);

        $response = $this->getJson("/api/reports/billings?customer_id={$customer->id}")->assertOk();

        $this->assertSame(3, $response->json('totals.count'));
        $this->assertSame('300.00', $response->json('totals.original_amount'));
    }

    public function test_total_interest_sums_the_filtered_set(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        // 1000 * 1.02^1 = 1020.00 -> 20.00 of interest, three times over.
        Billing::factory()->count(3)->overdue(30)->create([
            'original_amount' => '1000.00', 'monthly_interest_rate' => '0.0200',
        ]);

        $response = $this->getJson('/api/reports/billings')->assertOk();

        $this->assertSame('60.00', $response->json('totals.interest_amount'));
        $this->assertSame('3060.00', $response->json('totals.updated_amount'));
    }

    public function test_received_and_outstanding_are_kept_apart(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->count(2)->paid()->create([
            'original_amount' => '500.00', 'monthly_interest_rate' => '0.0000',
        ]);
        Billing::factory()->count(3)->create([
            'original_amount' => '200.00', 'monthly_interest_rate' => '0.0000',
        ]);

        $response = $this->getJson('/api/reports/billings')->assertOk();

        // Received comes from the frozen columns; pending, from the updated amount.
        $this->assertSame('1000.00', $response->json('totals.paid_amount'));
        $this->assertSame('600.00', $response->json('totals.pending_amount'));
    }

    // --- eco dos filtros ----------------------------------------------

    public function test_the_response_echoes_the_applied_filters(): void
    {
        $this->actingAsUser();

        // The exports have to print the period and filters in the file, and they should come
        // from the same source the screen uses.
        $response = $this->getJson(
            '/api/reports/billings?date_field=issue_date&start_date=2026-01-01&end_date=2026-12-31&status=paid',
        )->assertOk();

        $this->assertSame('issue_date', $response->json('filters.date_field'));
        $this->assertSame('2026-01-01', $response->json('filters.start_date'));
        $this->assertSame('2026-12-31', $response->json('filters.end_date'));
        $this->assertSame('paid', $response->json('filters.status'));
    }

    public function test_the_response_says_whether_the_pdf_fits_the_cap(): void
    {
        $this->actingAsUser();
        config(['reports.pdf_max_rows' => 3]);

        Billing::factory()->count(2)->create();
        $this->getJson('/api/reports/billings')
            ->assertOk()
            ->assertJsonPath('export.pdf_available', true)
            ->assertJsonPath('export.pdf_max_rows', 3);

        Billing::factory()->count(2)->create();

        // The screen uses this to disable the button before the click, rather than sending the
        // user into a 422.
        $this->getJson('/api/reports/billings')
            ->assertOk()
            ->assertJsonPath('export.pdf_available', false);
    }

    public function test_the_listing_does_not_issue_n_plus_one_queries(): void
    {
        $this->actingAsUser();
        Billing::factory()->count(10)->create();

        DB::enableQueryLog();
        $this->getJson('/api/reports/billings?per_page=10')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // the pagination count + rows + customers + data version + totals aggregation. The
        // version arrived with the totals cache: it is one fixed query per call, and what this
        // test proves — that the query count does not grow with the row count — still holds.
        $this->assertLessThanOrEqual(5, count($queries));
    }
}
