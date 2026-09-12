<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Importação de clientes por CSV.
 *
 * A regra que governa estes testes: **importação parcial é aceitável, desde que
 * o usuário saiba exatamente o que entrou e o que não entrou**. Um arquivo com
 * erro na linha 3 não pode nem abortar tudo nem entrar em silêncio — as boas
 * entram, e as ruins voltam nomeadas, com a linha e o motivo.
 */
class CustomerCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function csv(string $conteudo, string $nome = 'clientes.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, $conteudo);
    }

    /** Três válidas e duas inválidas — o critério de aceite do bloco. */
    private function arquivoMisto(): UploadedFile
    {
        return $this->csv(<<<'CSV'
        nome;documento;email;status
        Comércio Silva LTDA;12345678000190;financeiro@silva.test;ativo
        Padaria do Bairro ME;98765432000155;contato@padaria.test;ativo
        Documento Curto ME;123;curto@exemplo.test;ativo
        Transportes Aurora SA;11222333000181;aurora@exemplo.test;inativo
        Sem Email LTDA;44555666000177;;ativo
        CSV);
    }

    // --- proteção -----------------------------------------------------

    public function test_importacao_exige_autenticacao(): void
    {
        $this->postJson('/api/customers/import', [
            'file' => $this->csv("nome;documento;email;status\n"),
        ])->assertUnauthorized();
    }

    // --- prévia -------------------------------------------------------

    public function test_previa_nao_grava_nada(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers/import?preview=1', [
            'file' => $this->arquivoMisto(),
        ])->assertOk();

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_previa_conta_validas_e_invalidas_antes_de_confirmar(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers/import?preview=1', [
            'file' => $this->arquivoMisto(),
        ])
            ->assertOk()
            ->assertJsonPath('total_rows', 5)
            ->assertJsonPath('valid_count', 3)
            ->assertJsonPath('error_count', 2)
            // Nada foi importado: é prévia.
            ->assertJsonPath('imported_count', 0);
    }

    public function test_previa_mostra_as_primeiras_linhas_ja_normalizadas(): void
    {
        $this->actingAsUser();

        $resposta = $this->postJson('/api/customers/import?preview=1', [
            'file' => $this->arquivoMisto(),
        ])->assertOk();

        $primeira = $resposta->json('sample.0');

        $this->assertSame('Comércio Silva LTDA', $primeira['name']);
        $this->assertSame('12345678000190', $primeira['document']);
        // "ativo" do arquivo vira o valor que o banco guarda.
        $this->assertSame('active', $primeira['status']);
    }

    // --- importação ---------------------------------------------------

    public function test_importa_as_validas_e_nomeia_as_que_falharam(): void
    {
        $this->actingAsUser();

        $resposta = $this->postJson('/api/customers/import', [
            'file' => $this->arquivoMisto(),
        ])->assertOk();

        $this->assertSame(3, Customer::query()->count());
        $resposta->assertJsonPath('imported_count', 3);
        $resposta->assertJsonPath('error_count', 2);

        $erros = collect($resposta->json('errors'));

        // A linha é a do ARQUIVO, contando o cabeçalho: quem abre no Excel
        // precisa ir direto na linha certa.
        $this->assertSame([4, 6], $erros->pluck('line')->all());
        $this->assertStringContainsString('CPF', $erros[0]['messages'][0]);
        $this->assertStringContainsString('e-mail', $erros[1]['messages'][0]);
    }

    public function test_o_erro_traz_a_linha_crua_para_o_usuario_se_reconhecer(): void
    {
        $this->actingAsUser();

        $erro = $this->postJson('/api/customers/import', [
            'file' => $this->arquivoMisto(),
        ])->assertOk()->json('errors.0');

        $this->assertSame('Documento Curto ME', $erro['values']['name']);
    }

    public function test_documento_repetido_dentro_do_arquivo_entra_uma_vez_so(): void
    {
        $this->actingAsUser();

        $resposta = $this->postJson('/api/customers/import', [
            'file' => $this->csv(<<<'CSV'
            nome;documento;email;status
            Primeiro LTDA;12345678000190;primeiro@exemplo.test;ativo
            Repetido LTDA;12345678000190;repetido@exemplo.test;ativo
            CSV),
        ])->assertOk();

        $this->assertSame(1, Customer::query()->count());
        $resposta->assertJsonPath('imported_count', 1);
        $this->assertStringContainsString(
            'repetido',
            mb_strtolower($resposta->json('errors.0.messages.0')),
        );
    }

    public function test_documento_que_ja_existe_no_banco_e_recusado(): void
    {
        $this->actingAsUser();
        Customer::factory()->create(['document' => '12345678000190']);

        $resposta = $this->postJson('/api/customers/import', [
            'file' => $this->csv(<<<'CSV'
            nome;documento;email;status
            Novo LTDA;12345678000190;novo@exemplo.test;ativo
            CSV),
        ])->assertOk();

        $this->assertSame(1, Customer::query()->count());
        $resposta->assertJsonPath('imported_count', 0);
        $resposta->assertJsonPath('error_count', 1);
    }

    // --- formato do arquivo -------------------------------------------

    public function test_aceita_virgula_como_separador(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers/import', [
            'file' => $this->csv("nome,documento,email,status\nAcme LTDA,12345678000190,acme@exemplo.test,ativo\n"),
        ])->assertOk()->assertJsonPath('imported_count', 1);
    }

    public function test_aceita_cabecalho_em_ingles(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers/import', [
            'file' => $this->csv("name;document;email;status\nAcme LTDA;12345678000190;acme@exemplo.test;active\n"),
        ])->assertOk()->assertJsonPath('imported_count', 1);
    }

    /** O documento pode vir com máscara: o banco guarda só dígitos. */
    public function test_documento_com_mascara_e_normalizado(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers/import', [
            'file' => $this->csv("nome;documento;email;status\nAcme LTDA;12.345.678/0001-90;acme@exemplo.test;ativo\n"),
        ])->assertOk()->assertJsonPath('imported_count', 1);

        $this->assertSame('12345678000190', Customer::query()->value('document'));
    }

    public function test_arquivo_sem_as_colunas_obrigatorias_e_recusado_inteiro(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers/import', [
            'file' => $this->csv("nome;telefone\nAcme LTDA;1199999999\n"),
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', fn (string $mensagem) => str_contains($mensagem, 'documento'));
    }

    public function test_recusa_arquivo_que_nao_e_csv(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers/import', [
            'file' => UploadedFile::fake()->create('planilha.xlsx', 10),
        ])->assertStatus(422);
    }

    /**
     * O arquivo é lido linha a linha, e é isso que permite importar um CSV
     * maior que a memória do processo. Cinco mil linhas dentro de um limite
     * apertado não provam streaming sozinhas, mas provam que o conjunto inteiro
     * não está sendo materializado — que é o erro que se quer impedir.
     */
    public function test_importa_arquivo_grande_sem_acumular_em_memoria(): void
    {
        $this->actingAsUser();

        $linhas = ['nome;documento;email;status'];

        for ($i = 1; $i <= 5_000; $i++) {
            $documento = str_pad((string) $i, 11, '0', STR_PAD_LEFT);
            $linhas[] = "Cliente {$i};{$documento};cliente{$i}@exemplo.test;ativo";
        }

        $antes = memory_get_peak_usage(true);

        $this->postJson('/api/customers/import', [
            'file' => $this->csv(implode("\n", $linhas)),
        ])->assertOk()->assertJsonPath('imported_count', 5_000);

        $cresceu = (memory_get_peak_usage(true) - $antes) / 1024 / 1024;

        $this->assertSame(5_000, Customer::query()->count());
        $this->assertLessThan(
            32,
            $cresceu,
            "A importação cresceu {$cresceu} MB de pico: o arquivo está sendo acumulado.",
        );
    }
}
