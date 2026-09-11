<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A exportação devolve StreamedResponse: `assertSee` e `getContent()` não
 * funcionam nela, porque o corpo só existe quando o callback roda. Tudo aqui
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

    public function test_exportacao_exige_autenticacao(): void
    {
        $this->getJson('/api/reports/billings/csv')->assertUnauthorized();
    }

    public function test_responde_como_arquivo_csv_para_download(): void
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

    public function test_arquivo_traz_periodo_e_filtros_no_topo(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $customer = Customer::factory()->create(['name' => 'Padaria Aurora']);

        $conteudo = $this->exportar([
            'date_field' => 'issue_date',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
            'customer_id' => $customer->id,
            'status' => 'paid',
        ]);

        // O teste exige que o arquivo identifique período e filtros usados.
        $this->assertStringContainsString('Data de emissão', $conteudo);
        $this->assertStringContainsString('01/01/2026', $conteudo);
        $this->assertStringContainsString('31/03/2026', $conteudo);
        $this->assertStringContainsString('Padaria Aurora', $conteudo);
        $this->assertStringContainsString('Paga', $conteudo);
    }

    public function test_arquivo_traz_o_cabecalho_das_colunas(): void
    {
        $this->actingAsUser();

        $conteudo = $this->exportar();

        foreach ([
            'Cliente', 'Descrição', 'Emissão', 'Vencimento', 'Status',
            'Valor original', 'Juros', 'Valor atualizado', 'Valor pago',
        ] as $coluna) {
            $this->assertStringContainsString($coluna, $conteudo);
        }
    }

    public function test_arquivo_traz_os_totalizadores_no_rodape(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        Billing::factory()->count(3)->overdue(30)->create([
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
        ]);

        $conteudo = $this->exportar();
        $rodape = substr($conteudo, (int) strpos($conteudo, 'TOTAIS'));

        $this->assertStringContainsString('TOTAIS', $conteudo);
        // 3 x 1000 original, 3 x 20 de juros, 3060 atualizado.
        $this->assertStringContainsString('3.000,00', $rodape);
        $this->assertStringContainsString('60,00', $rodape);
        $this->assertStringContainsString('3.060,00', $rodape);
    }

    public function test_exportacao_respeita_o_filtro(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $dentro = Customer::factory()->create(['name' => 'Cliente Incluido']);
        $fora = Customer::factory()->create(['name' => 'Cliente Excluido']);

        Billing::factory()->count(2)->for($dentro)->create(['description' => 'Cobranca dentro']);
        Billing::factory()->count(5)->for($fora)->create(['description' => 'Cobranca fora']);

        $conteudo = $this->exportar(['customer_id' => $dentro->id]);

        // Contar linhas não basta: é preciso afirmar que o que está fora do
        // filtro realmente não aparece.
        $this->assertStringContainsString('Cobranca dentro', $conteudo);
        $this->assertStringNotContainsString('Cobranca fora', $conteudo);
        $this->assertStringNotContainsString('Cliente Excluido', $conteudo);
    }

    public function test_numero_de_linhas_corresponde_ao_conjunto_filtrado(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        $customer = Customer::factory()->create();
        Billing::factory()->count(7)->for($customer)->create();
        Billing::factory()->count(4)->create();

        $linhas = $this->dataRows($this->exportar(['customer_id' => $customer->id]));

        // Sete linhas de dados, e o totalizador — que vem de outra consulta —
        // concordando com esse número.
        $this->assertCount(7, $linhas);
        $this->assertSame('7', $this->totalsRow($this->exportar(['customer_id' => $customer->id]))[1]);
    }

    /**
     * Linhas de dados de verdade: o que está entre o cabeçalho das colunas e
     * o bloco de totais. Parsear é mais honesto que procurar substring — um
     * grep por texto acharia o mesmo termo no cabeçalho e no rodapé.
     *
     * @return array<int, array<int, string>>
     */
    private function dataRows(string $conteudo): array
    {
        $linhas = [];
        $dentro = false;

        foreach (explode("\n", trim($conteudo)) as $linha) {
            $campos = str_getcsv(trim($linha), ';', '"', '\\');

            if (($campos[0] ?? '') === 'Cliente' && ($campos[1] ?? '') === 'Descrição') {
                $dentro = true;

                continue;
            }

            if (($campos[0] ?? '') === 'TOTAIS') {
                break;
            }

            if ($dentro && trim($linha) !== '') {
                $linhas[] = $campos;
            }
        }

        return $linhas;
    }

    /**
     * @return array<int, string>
     */
    private function totalsRow(string $conteudo): array
    {
        $linhas = explode("\n", trim($conteudo));

        foreach ($linhas as $i => $linha) {
            if (str_starts_with(trim($linha), 'TOTAIS')) {
                return str_getcsv(trim($linhas[$i + 1]), ';', '"', '\\');
            }
        }

        return [];
    }

    public function test_exportacao_nao_pagina(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        // Mais registros do que caberia numa página do relatório: a
        // exportação leva o conjunto inteiro.
        Billing::factory()->count(60)->create(['description' => 'Linha exportada']);

        $conteudo = $this->exportar();

        $this->assertSame(60, substr_count($conteudo, 'Linha exportada'));
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    private function exportar(array $filtros = []): string
    {
        $query = http_build_query($filtros);

        $response = $this->get('/api/reports/billings/csv'.($query ? "?{$query}" : ''));
        $response->assertOk();

        // O corpo de um StreamedResponse só existe depois que o callback roda.
        return $response->streamedContent();
    }
}
