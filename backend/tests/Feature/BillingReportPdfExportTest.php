<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Não há asserção sobre o binário do PDF: ele não é estável nem legível, e
 * afirmar sobre bytes de um documento gerado é teste que quebra sozinho.
 *
 * O que se testa é o que é verificável: o status, o content-type, e sobretudo
 * o TETO — que é a decisão de projeto desta exportação.
 */
class BillingReportPdfExportTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-06-15 09:30:00';

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_exportacao_pdf_exige_autenticacao(): void
    {
        $this->getJson('/api/reports/billings/pdf')->assertUnauthorized();
    }

    public function test_responde_como_arquivo_pdf_para_download(): void
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

    public function test_o_arquivo_gerado_e_mesmo_um_pdf(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();
        Billing::factory()->count(2)->create();

        $response = $this->get('/api/reports/billings/pdf');

        // Única asserção sobre o conteúdo, e é sobre a assinatura do formato,
        // não sobre o que está desenhado dentro.
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    // --- o teto ------------------------------------------------------

    public function test_abaixo_do_teto_a_exportacao_funciona(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        config(['reports.pdf_max_rows' => 5]);
        Billing::factory()->count(5)->create();

        $this->get('/api/reports/billings/pdf')->assertOk();
    }

    public function test_acima_do_teto_responde_422_orientando_o_csv(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        config(['reports.pdf_max_rows' => 5]);
        Billing::factory()->count(6)->create();

        $response = $this->get('/api/reports/billings/pdf')->assertUnprocessable();

        // A mensagem precisa dizer o que fazer, não só que falhou.
        $this->assertStringContainsStringIgnoringCase('csv', $response->json('message'));
        $this->assertSame(6, $response->json('count'));
        $this->assertSame(5, $response->json('limit'));
    }

    public function test_o_teto_considera_o_conjunto_filtrado_e_nao_a_tabela(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        config(['reports.pdf_max_rows' => 5]);

        $customer = Customer::factory()->create();
        Billing::factory()->count(3)->for($customer)->create();
        Billing::factory()->count(20)->create();

        // Vinte e três cobranças na tabela, mas o filtro deixa três: o PDF
        // tem que sair. Checar o tamanho da tabela em vez do recorte tornaria
        // a exportação inútil em qualquer base real.
        $this->get("/api/reports/billings/pdf?customer_id={$customer->id}")->assertOk();
    }

    public function test_o_teto_nao_dispara_com_o_valor_exato(): void
    {
        $this->travelTo(self::HOJE);
        $this->actingAsUser();

        config(['reports.pdf_max_rows' => 4]);
        Billing::factory()->count(4)->create();

        // Limite é teto inclusivo: exatamente 4 passa, 5 não.
        $this->get('/api/reports/billings/pdf')->assertOk();
    }

    public function test_conjunto_vazio_gera_pdf_em_vez_de_erro(): void
    {
        $this->actingAsUser();

        // Filtro que não casa nada é resultado legítimo, não falha.
        $this->get('/api/reports/billings/pdf?start_date=2000-01-01&end_date=2000-01-02')
            ->assertOk();
    }

    public function test_filtros_invalidos_sao_rejeitados_como_no_relatorio(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/reports/billings/pdf?date_field=created_at')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_field');
    }
}
