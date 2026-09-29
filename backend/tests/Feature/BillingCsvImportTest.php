<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingStatus;
use App\Models\Billing;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Importing billings from a CSV.
 *
 * The same structure as the customer import, with two extra rules that are the
 * assunto destes testes:
 *
 *   The customer is resolved by DOCUMENT. The file comes from outside and does not know the
 *   internal id; the document is the business identity both ends have.
 *
 *   An imported billing IS BORN PENDING, like one created through the screen. Status and payment
 *   amounts are not accepted from the file — what makes that transition is recording a payment,
 *   which writes the frozen amounts along with it.
 */
class BillingCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cobrancas.csv', $content);
    }

    private function customer(string $documento): Customer
    {
        return Customer::factory()->create(['document' => $documento]);
    }

    // --- protection ---------------------------------------------------

    public function test_importing_requires_authentication(): void
    {
        $this->postJson('/api/billings/import', [
            'file' => $this->csv("documento;descricao;valor;taxa;emissao;vencimento\n"),
        ])->assertUnauthorized();
    }

    public function test_the_preview_writes_nothing(): void
    {
        $this->actingAsUser();
        $this->customer('12345678000190');

        $this->postJson('/api/billings/import?preview=1', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Mensalidade;1500,00;0,02;2026-07-10;2026-08-09
            CSV),
        ])->assertOk()->assertJsonPath('valid_count', 1);

        $this->assertSame(0, Billing::query()->count());
    }

    // --- resolving the customer ---------------------------------------

    public function test_the_customer_is_resolved_by_document(): void
    {
        $this->actingAsUser();
        $cliente = $this->customer('12345678000190');

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Mensalidade de agosto;1500,00;0,02;2026-07-10;2026-08-09
            CSV),
        ])->assertOk()->assertJsonPath('imported_count', 1);

        $this->assertSame($cliente->id, Billing::query()->value('customer_id'));
    }

    /** The document may arrive formatted, as in the customer import. */
    public function test_a_formatted_document_still_finds_the_customer(): void
    {
        $this->actingAsUser();
        $cliente = $this->customer('12345678000190');

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12.345.678/0001-90;Mensalidade;1500,00;0,02;2026-07-10;2026-08-09
            CSV),
        ])->assertOk()->assertJsonPath('imported_count', 1);

        $this->assertSame($cliente->id, Billing::query()->value('customer_id'));
    }

    public function test_a_missing_customer_fails_only_its_own_row(): void
    {
        $this->actingAsUser();
        $this->customer('12345678000190');

        $response = $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Entra;1500,00;0,02;2026-07-10;2026-08-09
            99999999000199;Não entra;800,00;0,02;2026-07-10;2026-08-09
            CSV),
        ])->assertOk();

        $this->assertSame(1, Billing::query()->count());
        $response->assertJsonPath('imported_count', 1);
        $response->assertJsonPath('errors.0.line', 3);
        $this->assertStringContainsString(
            'cliente',
            mb_strtolower($response->json('errors.0.messages.0')),
        );
    }

    // --- the billing is born pending ----------------------------------

    public function test_an_imported_billing_is_born_pending(): void
    {
        $this->actingAsUser();
        $this->customer('12345678000190');

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Mensalidade;1500,00;0,02;2026-07-10;2026-08-09
            CSV),
        ])->assertOk();

        $billing = Billing::query()->firstOrFail();

        $this->assertSame(BillingStatus::Pending, $billing->status);
        $this->assertNull($billing->payment_date);
        $this->assertNull($billing->paid_amount);
        $this->assertNull($billing->paid_interest_amount);
    }

    /**
     * A status or payment column in the file is IGNORED, not accepted. Accepting it would create
     * a paid billing without the frozen amounts — the same reason the create form does not have
     * those fields.
     */
    public function test_status_and_payment_coming_from_the_file_are_ignored(): void
    {
        $this->actingAsUser();
        $this->customer('12345678000190');

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento;status;valor_pago
            12345678000190;Mensalidade;1500,00;0,02;2026-07-10;2026-08-09;paid;1500,00
            CSV),
        ])->assertOk()->assertJsonPath('imported_count', 1);

        $billing = Billing::query()->firstOrFail();

        $this->assertSame(BillingStatus::Pending, $billing->status);
        $this->assertNull($billing->paid_amount);
    }

    // --- formats that come out of spreadsheets ------------------------

    public function test_accepts_an_amount_in_brazilian_format(): void
    {
        $this->actingAsUser();
        $this->customer('12345678000190');

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Mensalidade;1.234,56;0,035;2026-07-10;2026-08-09
            CSV),
        ])->assertOk()->assertJsonPath('imported_count', 1);

        $billing = Billing::query()->firstOrFail();

        $this->assertSame('1234.56', $billing->original_amount);
        $this->assertSame('0.0350', $billing->monthly_interest_rate);
    }

    public function test_accepts_a_date_in_brazilian_format(): void
    {
        $this->actingAsUser();
        $this->customer('12345678000190');

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Mensalidade;1500,00;0,02;10/07/2026;09/08/2026
            CSV),
        ])->assertOk()->assertJsonPath('imported_count', 1);

        $billing = Billing::query()->firstOrFail();

        $this->assertSame('2026-07-10', $billing->issue_date->toDateString());
        $this->assertSame('2026-08-09', $billing->due_date->toDateString());
    }

    public function test_a_due_date_before_the_issue_date_is_refused(): void
    {
        $this->actingAsUser();
        $this->customer('12345678000190');

        $response = $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Invertida;1500,00;0,02;2026-08-09;2026-07-10
            CSV),
        ])->assertOk();

        $this->assertSame(0, Billing::query()->count());
        $response->assertJsonPath('error_count', 1);
    }

    public function test_a_file_missing_the_required_columns_is_refused_whole(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/billings/import', [
            'file' => $this->csv("documento;descricao\n12345678000190;Mensalidade\n"),
        ])->assertStatus(422);
    }

    /**
     * The same billing can be imported twice: a billing has no natural key. Two monthly charges
     * for the same customer, the same amount and the same due date, are two legitimate billings.
     */
    public function test_identical_rows_create_two_billings(): void
    {
        $this->actingAsUser();
        $this->customer('12345678000190');

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Mensalidade;1500,00;0,02;2026-07-10;2026-08-09
            12345678000190;Mensalidade;1500,00;0,02;2026-07-10;2026-08-09
            CSV),
        ])->assertOk()->assertJsonPath('imported_count', 2);

        $this->assertSame(2, Billing::query()->count());
    }

    /**
     * A thousand billings spread across a hundred customers: what gets asserted is that
     * resolving the customer does NOT become one query per row. Without batching, this file would
     * fire a thousand queries.
     */
    public function test_imports_a_large_file_without_a_query_per_row(): void
    {
        $this->actingAsUser();

        $documents = [];

        for ($i = 1; $i <= 100; $i++) {
            $documento = str_pad((string) $i, 14, '0', STR_PAD_LEFT);
            $this->customer($documento);
            $documents[] = $documento;
        }

        $rows = ['documento;descricao;valor;taxa;emissao;vencimento'];

        for ($i = 0; $i < 1_000; $i++) {
            $documento = $documents[$i % 100];
            $rows[] = "{$documento};Mensalidade {$i};1000,00;0,02;2026-07-10;2026-08-09";
        }

        DB::enableQueryLog();

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(implode("\n", $rows)),
        ])->assertOk()->assertJsonPath('imported_count', 1_000);

        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1_000, Billing::query()->count());
        // Two batches of 500: one customer query and one insert per batch, plus the session's
        // token. A long way from the thousand one query per row would give.
        $this->assertLessThan(20, $consultas, "Foram {$consultas} consultas.");
    }
}
