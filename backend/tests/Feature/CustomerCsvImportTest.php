<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Importing customers from a CSV.
 *
 * The rule that governs these tests: **a partial import is acceptable, as long as the user knows
 * exactly what went in and what did not**. A file with an error on line 3 must neither abort
 * everything nor go in silently — the good ones go in, and the bad ones come back named, with
 * the line and the reason.
 */
class CustomerCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function csv(string $content, string $nome = 'clientes.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, $content);
    }

    /** Three valid and two invalid — the block's acceptance criterion. */
    private function mixedFile(): UploadedFile
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

    // --- protection ---------------------------------------------------

    public function test_importing_requires_authentication(): void
    {
        $this->postJson('/api/customers/import', [
            'file' => $this->csv("nome;documento;email;status\n"),
        ])->assertUnauthorized();
    }

    // --- prévia -------------------------------------------------------

    public function test_the_preview_writes_nothing(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers/import?preview=1', [
            'file' => $this->mixedFile(),
        ])->assertOk();

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_the_preview_counts_valid_and_invalid_rows_before_confirming(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers/import?preview=1', [
            'file' => $this->mixedFile(),
        ])
            ->assertOk()
            ->assertJsonPath('total_rows', 5)
            ->assertJsonPath('valid_count', 3)
            ->assertJsonPath('error_count', 2)
            // Nothing was imported: this is a preview.
            ->assertJsonPath('imported_count', 0);
    }

    public function test_the_preview_shows_the_first_rows_already_normalized(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/customers/import?preview=1', [
            'file' => $this->mixedFile(),
        ])->assertOk();

        $firstOne = $response->json('sample.0');

        $this->assertSame('Comércio Silva LTDA', $firstOne['name']);
        $this->assertSame('12345678000190', $firstOne['document']);
        // "ativo" from the file becomes the value the database stores.
        $this->assertSame('active', $firstOne['status']);
    }

    // --- importing ----------------------------------------------------

    public function test_imports_the_valid_rows_and_names_the_ones_that_failed(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/customers/import', [
            'file' => $this->mixedFile(),
        ])->assertOk();

        $this->assertSame(3, Customer::query()->count());
        $response->assertJsonPath('imported_count', 3);
        $response->assertJsonPath('error_count', 2);

        $errors = collect($response->json('errors'));

        // The line is the FILE's, counting the header: whoever opens it in Excel needs to go
        // straight to the right line.
        $this->assertSame([4, 6], $errors->pluck('line')->all());
        $this->assertStringContainsString('CPF', $errors[0]['messages'][0]);
        $this->assertStringContainsString('e-mail', $errors[1]['messages'][0]);
    }

    public function test_the_error_carries_the_raw_row_for_the_user_to_recognize(): void
    {
        $this->actingAsUser();

        $error = $this->postJson('/api/customers/import', [
            'file' => $this->mixedFile(),
        ])->assertOk()->json('errors.0');

        $this->assertSame('Documento Curto ME', $error['values']['name']);
    }

    public function test_a_document_repeated_within_the_file_is_inserted_once(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/customers/import', [
            'file' => $this->csv(<<<'CSV'
            nome;documento;email;status
            Primeiro LTDA;12345678000190;primeiro@exemplo.test;ativo
            Repetido LTDA;12345678000190;repetido@exemplo.test;ativo
            CSV),
        ])->assertOk();

        $this->assertSame(1, Customer::query()->count());
        $response->assertJsonPath('imported_count', 1);
        $this->assertStringContainsString(
            'repetido',
            mb_strtolower($response->json('errors.0.messages.0')),
        );
    }

    public function test_a_document_that_already_exists_is_refused(): void
    {
        $this->actingAsUser();
        Customer::factory()->create(['document' => '12345678000190']);

        $response = $this->postJson('/api/customers/import', [
            'file' => $this->csv(<<<'CSV'
            nome;documento;email;status
            Novo LTDA;12345678000190;novo@exemplo.test;ativo
            CSV),
        ])->assertOk();

        $this->assertSame(1, Customer::query()->count());
        $response->assertJsonPath('imported_count', 0);
        $response->assertJsonPath('error_count', 1);
    }

    // --- the file's format --------------------------------------------

    public function test_accepts_a_comma_as_the_separator(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers/import', [
            'file' => $this->csv("nome,documento,email,status\nAcme LTDA,12345678000190,acme@exemplo.test,ativo\n"),
        ])->assertOk()->assertJsonPath('imported_count', 1);
    }

    public function test_accepts_an_english_header(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers/import', [
            'file' => $this->csv("name;document;email;status\nAcme LTDA;12345678000190;acme@exemplo.test;active\n"),
        ])->assertOk()->assertJsonPath('imported_count', 1);
    }

    /** The document may arrive formatted: the database stores digits only. */
    public function test_a_formatted_document_is_normalized(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers/import', [
            'file' => $this->csv("nome;documento;email;status\nAcme LTDA;12.345.678/0001-90;acme@exemplo.test;ativo\n"),
        ])->assertOk()->assertJsonPath('imported_count', 1);

        $this->assertSame('12345678000190', Customer::query()->value('document'));
    }

    public function test_a_file_missing_the_required_columns_is_refused_whole(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers/import', [
            'file' => $this->csv("nome;telefone\nAcme LTDA;1199999999\n"),
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', fn (string $message) => str_contains($message, 'documento'));
    }

    public function test_refuses_a_file_that_is_not_csv(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers/import', [
            'file' => UploadedFile::fake()->create('planilha.xlsx', 10),
        ])->assertStatus(422);
    }

    /**
     * The file is read row by row, and that is what makes it possible to import a CSV larger
     * than the process's memory. Five thousand rows within a tight limit do not prove streaming
     * on their own, but they do prove the whole set is not being materialised — which is the
     * mistake this is meant to prevent.
     */
    public function test_imports_a_large_file_without_accumulating_it_in_memory(): void
    {
        $this->actingAsUser();

        $rows = ['nome;documento;email;status'];

        for ($i = 1; $i <= 5_000; $i++) {
            $documento = str_pad((string) $i, 11, '0', STR_PAD_LEFT);
            $rows[] = "Cliente {$i};{$documento};cliente{$i}@exemplo.test;ativo";
        }

        $before = memory_get_peak_usage(true);

        $this->postJson('/api/customers/import', [
            'file' => $this->csv(implode("\n", $rows)),
        ])->assertOk()->assertJsonPath('imported_count', 5_000);

        $cresceu = (memory_get_peak_usage(true) - $before) / 1024 / 1024;

        $this->assertSame(5_000, Customer::query()->count());
        $this->assertLessThan(
            32,
            $cresceu,
            "A importação cresceu {$cresceu} MB de pico: o arquivo está sendo acumulado.",
        );
    }
}
