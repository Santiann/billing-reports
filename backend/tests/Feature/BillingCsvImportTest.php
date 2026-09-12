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
 * Importação de cobranças por CSV.
 *
 * Mesma estrutura da importação de clientes, com duas regras a mais que são o
 * assunto destes testes:
 *
 *   O cliente é resolvido por DOCUMENTO. O arquivo vem de fora e não conhece o
 *   id interno; documento é a identidade de negócio que as duas pontas têm.
 *
 *   Cobrança importada NASCE PENDENTE, como a cadastrada pela tela. Status e
 *   valores de pagamento não são aceitos do arquivo — quem faz essa transição é
 *   o registro de pagamento, que grava os valores congelados junto.
 */
class BillingCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function csv(string $conteudo): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cobrancas.csv', $conteudo);
    }

    private function cliente(string $documento): Customer
    {
        return Customer::factory()->create(['document' => $documento]);
    }

    // --- proteção -----------------------------------------------------

    public function test_importacao_exige_autenticacao(): void
    {
        $this->postJson('/api/billings/import', [
            'file' => $this->csv("documento;descricao;valor;taxa;emissao;vencimento\n"),
        ])->assertUnauthorized();
    }

    public function test_previa_nao_grava_nada(): void
    {
        $this->actingAsUser();
        $this->cliente('12345678000190');

        $this->postJson('/api/billings/import?preview=1', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Mensalidade;1500,00;0,02;2026-07-10;2026-08-09
            CSV),
        ])->assertOk()->assertJsonPath('valid_count', 1);

        $this->assertSame(0, Billing::query()->count());
    }

    // --- resolução do cliente -----------------------------------------

    public function test_cliente_e_resolvido_pelo_documento(): void
    {
        $this->actingAsUser();
        $cliente = $this->cliente('12345678000190');

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Mensalidade de agosto;1500,00;0,02;2026-07-10;2026-08-09
            CSV),
        ])->assertOk()->assertJsonPath('imported_count', 1);

        $this->assertSame($cliente->id, Billing::query()->value('customer_id'));
    }

    /** O documento pode vir com máscara, como na importação de clientes. */
    public function test_documento_com_mascara_encontra_o_cliente(): void
    {
        $this->actingAsUser();
        $cliente = $this->cliente('12345678000190');

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12.345.678/0001-90;Mensalidade;1500,00;0,02;2026-07-10;2026-08-09
            CSV),
        ])->assertOk()->assertJsonPath('imported_count', 1);

        $this->assertSame($cliente->id, Billing::query()->value('customer_id'));
    }

    public function test_cliente_inexistente_falha_so_na_linha_dele(): void
    {
        $this->actingAsUser();
        $this->cliente('12345678000190');

        $resposta = $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Entra;1500,00;0,02;2026-07-10;2026-08-09
            99999999000199;Não entra;800,00;0,02;2026-07-10;2026-08-09
            CSV),
        ])->assertOk();

        $this->assertSame(1, Billing::query()->count());
        $resposta->assertJsonPath('imported_count', 1);
        $resposta->assertJsonPath('errors.0.line', 3);
        $this->assertStringContainsString(
            'cliente',
            mb_strtolower($resposta->json('errors.0.messages.0')),
        );
    }

    // --- a cobrança nasce pendente ------------------------------------

    public function test_cobranca_importada_nasce_pendente(): void
    {
        $this->actingAsUser();
        $this->cliente('12345678000190');

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Mensalidade;1500,00;0,02;2026-07-10;2026-08-09
            CSV),
        ])->assertOk();

        $cobranca = Billing::query()->firstOrFail();

        $this->assertSame(BillingStatus::Pending, $cobranca->status);
        $this->assertNull($cobranca->payment_date);
        $this->assertNull($cobranca->paid_amount);
        $this->assertNull($cobranca->paid_interest_amount);
    }

    /**
     * Coluna de status ou de pagamento no arquivo é IGNORADA, não aceita.
     * Aceitar criaria cobrança paga sem os valores congelados — o mesmo motivo
     * pelo qual o formulário de cadastro não tem esses campos.
     */
    public function test_status_e_pagamento_vindos_do_arquivo_sao_ignorados(): void
    {
        $this->actingAsUser();
        $this->cliente('12345678000190');

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento;status;valor_pago
            12345678000190;Mensalidade;1500,00;0,02;2026-07-10;2026-08-09;paid;1500,00
            CSV),
        ])->assertOk()->assertJsonPath('imported_count', 1);

        $cobranca = Billing::query()->firstOrFail();

        $this->assertSame(BillingStatus::Pending, $cobranca->status);
        $this->assertNull($cobranca->paid_amount);
    }

    // --- formatos que vêm de planilha ---------------------------------

    public function test_aceita_valor_no_formato_brasileiro(): void
    {
        $this->actingAsUser();
        $this->cliente('12345678000190');

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Mensalidade;1.234,56;0,035;2026-07-10;2026-08-09
            CSV),
        ])->assertOk()->assertJsonPath('imported_count', 1);

        $cobranca = Billing::query()->firstOrFail();

        $this->assertSame('1234.56', $cobranca->original_amount);
        $this->assertSame('0.0350', $cobranca->monthly_interest_rate);
    }

    public function test_aceita_data_no_formato_brasileiro(): void
    {
        $this->actingAsUser();
        $this->cliente('12345678000190');

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Mensalidade;1500,00;0,02;10/07/2026;09/08/2026
            CSV),
        ])->assertOk()->assertJsonPath('imported_count', 1);

        $cobranca = Billing::query()->firstOrFail();

        $this->assertSame('2026-07-10', $cobranca->issue_date->toDateString());
        $this->assertSame('2026-08-09', $cobranca->due_date->toDateString());
    }

    public function test_vencimento_antes_da_emissao_e_recusado(): void
    {
        $this->actingAsUser();
        $this->cliente('12345678000190');

        $resposta = $this->postJson('/api/billings/import', [
            'file' => $this->csv(<<<'CSV'
            documento;descricao;valor;taxa;emissao;vencimento
            12345678000190;Invertida;1500,00;0,02;2026-08-09;2026-07-10
            CSV),
        ])->assertOk();

        $this->assertSame(0, Billing::query()->count());
        $resposta->assertJsonPath('error_count', 1);
    }

    public function test_arquivo_sem_as_colunas_obrigatorias_e_recusado_inteiro(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/billings/import', [
            'file' => $this->csv("documento;descricao\n12345678000190;Mensalidade\n"),
        ])->assertStatus(422);
    }

    /**
     * A mesma cobrança pode ser importada duas vezes: cobrança não tem chave
     * natural. Duas mensalidades do mesmo cliente, mesmo valor e mesmo
     * vencimento, são duas cobranças legítimas.
     */
    public function test_linhas_identicas_geram_duas_cobrancas(): void
    {
        $this->actingAsUser();
        $this->cliente('12345678000190');

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
     * Mil cobranças espalhadas por cem clientes: o que se afirma é que a
     * resolução do cliente NÃO vira uma consulta por linha. Sem o lote, este
     * arquivo dispararia mil consultas.
     */
    public function test_importa_arquivo_grande_sem_consulta_por_linha(): void
    {
        $this->actingAsUser();

        $documentos = [];

        for ($i = 1; $i <= 100; $i++) {
            $documento = str_pad((string) $i, 14, '0', STR_PAD_LEFT);
            $this->cliente($documento);
            $documentos[] = $documento;
        }

        $linhas = ['documento;descricao;valor;taxa;emissao;vencimento'];

        for ($i = 0; $i < 1_000; $i++) {
            $documento = $documentos[$i % 100];
            $linhas[] = "{$documento};Mensalidade {$i};1000,00;0,02;2026-07-10;2026-08-09";
        }

        DB::enableQueryLog();

        $this->postJson('/api/billings/import', [
            'file' => $this->csv(implode("\n", $linhas)),
        ])->assertOk()->assertJsonPath('imported_count', 1_000);

        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1_000, Billing::query()->count());
        // Dois lotes de 500: uma consulta de clientes e um insert por lote,
        // mais o token da sessão. Longe das mil que uma consulta por linha daria.
        $this->assertLessThan(20, $consultas, "Foram {$consultas} consultas.");
    }
}
