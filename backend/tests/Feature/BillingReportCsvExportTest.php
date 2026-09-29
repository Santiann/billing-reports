<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The export returns a StreamedResponse: `assertSee` and `getContent()` do not work on it,
 * because the body only exists once the callback runs. Everything here
 * passa por `streamedContent()`.
 */
class BillingReportCsvExportTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-06-15 09:30:00';

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_the_export_requires_authentication(): void
    {
        $this->getJson('/api/reports/billings/csv')->assertUnauthorized();
    }

    public function test_responds_as_a_csv_file_for_download(): void
    {
        $this->actingAsUser();

        $response = $this->get('/api/reports/billings/csv');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString('.csv', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_file_carries_the_period_and_filters_at_the_top(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $customer = Customer::factory()->create(['name' => 'Padaria Aurora']);

        $content = $this->exportar([
            'date_field' => 'issue_date',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
            'customer_id' => $customer->id,
            'status' => 'paid',
        ]);

        // The brief requires the file to identify the period and filters used.
        $this->assertStringContainsString('Data de emissão', $content);
        $this->assertStringContainsString('01/01/2026', $content);
        $this->assertStringContainsString('31/03/2026', $content);
        $this->assertStringContainsString('Padaria Aurora', $content);
        $this->assertStringContainsString('Paga', $content);
    }

    public function test_the_header_reflects_each_period_basis_and_status(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        // Each label is a branch of its own, and an unexercised branch is a label that can be
        // wrong without anyone noticing.
        $this->assertStringContainsString(
            'Data de pagamento',
            $this->exportar(['date_field' => 'payment_date']),
        );

        $this->assertStringContainsString(
            'Data de vencimento',
            $this->exportar(['date_field' => 'due_date']),
        );

        $this->assertStringContainsString(
            'Pendente',
            $this->exportar(['status' => 'pending']),
        );

        $this->assertStringContainsString(
            'Vencida',
            $this->exportar(['status' => 'overdue']),
        );
    }

    public function test_the_file_carries_the_column_header(): void
    {
        $this->actingAsUser();

        $content = $this->exportar();

        foreach ([
            'Cliente', 'Descrição', 'Emissão', 'Vencimento', 'Status',
            'Valor original', 'Juros', 'Valor atualizado', 'Valor pago',
        ] as $column) {
            $this->assertStringContainsString($column, $content);
        }
    }

    public function test_the_file_carries_the_totals_in_the_footer(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->count(3)->overdue(30)->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
        ]);

        $content = $this->exportar();
        $rodape = substr($content, (int) strpos($content, 'TOTAIS'));

        $this->assertStringContainsString('TOTAIS', $content);
        // 3 x 1000 original, 3 x 20 interest, 3060 updated.
        $this->assertStringContainsString('3.000,00', $rodape);
        $this->assertStringContainsString('60,00', $rodape);
        $this->assertStringContainsString('3.060,00', $rodape);
    }

    public function test_the_export_respects_the_filter(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $dentro = Customer::factory()->create(['name' => 'Cliente Incluido']);
        $fora = Customer::factory()->create(['name' => 'Cliente Excluido']);

        Billing::factory()->count(2)->for($dentro)->create(['description' => 'Cobranca dentro']);
        Billing::factory()->count(5)->for($fora)->create(['description' => 'Cobranca fora']);

        $content = $this->exportar(['customer_id' => $dentro->id]);

        // Counting rows is not enough: you have to assert that what falls outside the filter
        // really does not appear.
        $this->assertStringContainsString('Cobranca dentro', $content);
        $this->assertStringNotContainsString('Cobranca fora', $content);
        $this->assertStringNotContainsString('Cliente Excluido', $content);
    }

    public function test_the_row_count_matches_the_filtered_set(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $customer = Customer::factory()->create();
        Billing::factory()->count(7)->for($customer)->create();
        Billing::factory()->count(4)->create();

        $rows = $this->dataRows($this->exportar(['customer_id' => $customer->id]));

        // Seven data rows, and the total — which comes from another query — agreeing with that
        // number.
        $this->assertCount(7, $rows);
        $this->assertSame('7', $this->totalsRow($this->exportar(['customer_id' => $customer->id]))[1]);
    }

    /**
     * The actual data rows: what sits between the column header and the totals block. Parsing is
     * more honest than looking for a substring — a text grep would find the same term in the
     * header and in the footer.
     *
     * @return array<int, array<int, string>>
     */
    private function dataRows(string $content): array
    {
        $rows = [];
        $dentro = false;

        foreach (explode("\n", trim($content)) as $row) {
            $campos = str_getcsv(trim($row), ';', '"', '\\');

            if (($campos[0] ?? '') === 'Cliente' && ($campos[1] ?? '') === 'Descrição') {
                $dentro = true;

                continue;
            }

            if (($campos[0] ?? '') === 'TOTAIS') {
                break;
            }

            if ($dentro && trim($row) !== '') {
                $rows[] = $campos;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function totalsRow(string $content): array
    {
        $rows = explode("\n", trim($content));

        foreach ($rows as $i => $row) {
            if (str_starts_with(trim($row), 'TOTAIS')) {
                return str_getcsv(trim($rows[$i + 1]), ';', '"', '\\');
            }
        }

        return [];
    }

    public function test_the_export_does_not_paginate(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        // More records than would fit on one page of the report: the export takes the whole
        // set.
        Billing::factory()->count(60)->create(['description' => 'Linha exportada']);

        $content = $this->exportar();

        $this->assertSame(60, substr_count($content, 'Linha exportada'));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function exportar(array $filters = []): string
    {
        $query = http_build_query($filters);

        $response = $this->get('/api/reports/billings/csv'.($query ? "?{$query}" : ''));
        $response->assertOk();

        // A StreamedResponse's body only exists after the callback runs.
        return $response->streamedContent();
    }
}
