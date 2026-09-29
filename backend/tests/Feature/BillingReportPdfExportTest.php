<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * There is no assertion about the PDF's binary: it is neither stable nor readable, and asserting
 * on the bytes of a generated document is a test that breaks on its own.
 *
 * What gets tested is what is verifiable: the status, the content type, and above all the CAP —
 * which is this export's design decision.
 */
class BillingReportPdfExportTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-06-15 09:30:00';

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_the_pdf_export_requires_authentication(): void
    {
        $this->getJson('/api/reports/billings/pdf')->assertUnauthorized();
    }

    public function test_responds_as_a_pdf_file_for_download(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        Billing::factory()->count(3)->create();

        $response = $this->get('/api/reports/billings/pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_generated_file_really_is_a_pdf(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        Billing::factory()->count(2)->create();

        $response = $this->get('/api/reports/billings/pdf');

        // The only assertion about the content, and it is about the format's signature, not
        // about what is drawn inside.
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    // --- o teto ------------------------------------------------------

    public function test_below_the_cap_the_export_works(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        config(['reports.pdf_max_rows' => 5]);
        Billing::factory()->count(5)->create();

        $this->get('/api/reports/billings/pdf')->assertOk();
    }

    public function test_above_the_cap_it_responds_422_pointing_at_the_csv(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        config(['reports.pdf_max_rows' => 5]);
        Billing::factory()->count(6)->create();

        $response = $this->get('/api/reports/billings/pdf')->assertUnprocessable();

        // The message has to say what to do, not just that it failed.
        $this->assertStringContainsStringIgnoringCase('csv', $response->json('message'));
        $this->assertSame(6, $response->json('count'));
        $this->assertSame(5, $response->json('limit'));
    }

    public function test_the_cap_considers_the_filtered_set_and_not_the_table(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        config(['reports.pdf_max_rows' => 5]);

        $customer = Customer::factory()->create();
        Billing::factory()->count(3)->for($customer)->create();
        Billing::factory()->count(20)->create();

        // Twenty-three billings in the table, but the filter leaves three: the PDF has to come
        // out. Checking the table's size instead of the scope would make the export useless on
        // any real base.
        $this->get("/api/reports/billings/pdf?customer_id={$customer->id}")->assertOk();
    }

    public function test_the_cap_does_not_trip_at_the_exact_value(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        config(['reports.pdf_max_rows' => 4]);
        Billing::factory()->count(4)->create();

        // The limit is an inclusive cap: exactly 4 passes, 5 does not.
        $this->get('/api/reports/billings/pdf')->assertOk();
    }

    public function test_an_empty_set_yields_a_pdf_instead_of_an_error(): void
    {
        $this->actingAsUser();

        // A filter that matches nothing is a legitimate result, not a failure.
        $this->get('/api/reports/billings/pdf?start_date=2000-01-01&end_date=2000-01-02')
            ->assertOk();
    }

    public function test_invalid_filters_are_rejected_as_in_the_report(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/reports/billings/pdf?date_field=created_at')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_field');
    }
}
