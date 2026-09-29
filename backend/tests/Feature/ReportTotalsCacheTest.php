<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cache dos totalizadores do relatório.
 *
 * Um cache de totais financeiros só é aceitável se nunca servir número velho.
 * Por isso a maior parte destes testes não é sobre o cache acertar — é sobre
 * ele ERRAR na hora certa: toda operação que muda uma cobrança tem que fazer
 * a consulta seguinte recalcular.
 *
 * A contagem que importa é a da consulta de agregação, reconhecida pelo alias
 * `total_count`: zero quando o cache serve, uma quando recalcula.
 */
class ReportTotalsCacheTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-06-15 09:30:00';

    private int $agregacoes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::HOJE);
        Sanctum::actingAs(User::factory()->create());

        DB::listen(function ($consulta): void {
            if (str_contains($consulta->sql, 'total_count')) {
                $this->agregacoes++;
            }
        });
    }

    private function cliente(string $documento = '12345678000190'): Customer
    {
        return Customer::factory()->create(['document' => $documento]);
    }

    /** Vencida há 30 dias a 2% ao mês: 1.020,00 hoje. */
    private function cobranca(?Customer $cliente = null): Billing
    {
        return Billing::factory()->create([
            'customer_id' => ($cliente ?? $this->cliente())->id,
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);
    }

    /**
     * Totais do relatório, e quantas agregações a chamada disparou.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function totais(array $filtros = []): array
    {
        $antes = $this->agregacoes;

        $totais = $this->getJson('/api/reports/billings?'.http_build_query($filtros))
            ->assertOk()
            ->json('totals');

        return [$totais, $this->agregacoes - $antes];
    }

    // --- o cache serve ------------------------------------------------

    public function test_a_second_query_with_the_same_filters_does_not_recompute(): void
    {
        $this->cobranca();

        [$primeira, $calculos1] = $this->totais();
        [$segunda, $calculos2] = $this->totais();

        $this->assertSame(1, $calculos1);
        $this->assertSame(0, $calculos2);
        $this->assertSame($primeira, $segunda);
    }

    /**
     * Ordenação e página mudam as linhas, não o conjunto: os totais são os
     * mesmos, e recalculá-los a cada clique de ordenação desperdiçaria o cache
     * justamente no uso mais comum da tela.
     */
    public function test_sorting_and_paginating_reuse_the_totals(): void
    {
        $this->cobranca();
        $this->totais();

        [, $calculos] = $this->totais(['sort' => 'original_amount', 'direction' => 'asc', 'page' => 2]);

        $this->assertSame(0, $calculos);
    }

    public function test_different_filters_do_not_share_totals(): void
    {
        $a = $this->cliente('11111111000111');
        $b = $this->cliente('22222222000122');
        $this->cobranca($a);
        $this->cobranca($b);
        $this->cobranca($b);

        [$totaisA] = $this->totais(['customer_id' => $a->id]);
        [$totaisB, $calculos] = $this->totais(['customer_id' => $b->id]);

        $this->assertSame(1, $calculos);
        $this->assertSame(1, $totaisA['count']);
        $this->assertSame(2, $totaisB['count']);
    }

    /** O que sai do cache é exatamente o que a consulta calcularia. */
    public function test_the_cached_totals_match_the_queried_ones(): void
    {
        $this->cobranca();
        Billing::factory()->paidLate(45)->create();

        [$doCache] = $this->totais();
        [$outraVez] = $this->totais();

        Cache::flush();
        [$recalculados, $calculos] = $this->totais();

        $this->assertSame(1, $calculos);
        $this->assertSame($recalculados, $doCache);
        $this->assertSame($recalculados, $outraVez);
    }

    // --- o cache erra na hora certa -----------------------------------

    public function test_a_payment_invalidates_the_totals(): void
    {
        $cobranca = $this->cobranca();
        [$antes] = $this->totais();

        $this->postJson("/api/billings/{$cobranca->id}/payment")->assertOk();

        [$depois, $calculos] = $this->totais();

        $this->assertSame(1, $calculos);
        $this->assertSame('0.00', $antes['paid_amount']);
        $this->assertSame('1020.00', $depois['paid_amount']);
    }

    public function test_a_reversal_invalidates_the_totals(): void
    {
        $cobranca = $this->cobranca();
        $this->postJson("/api/billings/{$cobranca->id}/payment")->assertOk();
        [$antes] = $this->totais();

        $this->postJson("/api/billings/{$cobranca->id}/reversal")->assertOk();

        [$depois, $calculos] = $this->totais();

        $this->assertSame(1, $calculos);
        $this->assertSame('1020.00', $antes['paid_amount']);
        $this->assertSame('0.00', $depois['paid_amount']);
    }

    public function test_an_edit_invalidates_the_totals(): void
    {
        $cobranca = $this->cobranca();
        $this->totais();

        $this->putJson("/api/billings/{$cobranca->id}", [
            'customer_id' => $cobranca->customer_id,
            'description' => $cobranca->description,
            'original_amount' => '2000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ])->assertOk();

        [$depois, $calculos] = $this->totais();

        $this->assertSame(1, $calculos);
        $this->assertSame('2000.00', $depois['original_amount']);
    }

    /** Alteração fora de requisição também invalida: ela passa pela trilha. */
    public function test_a_change_from_the_console_invalidates_the_totals(): void
    {
        $cobranca = $this->cobranca();
        $this->totais();

        $cobranca->update(['original_amount' => '3000.00']);

        [$depois, $calculos] = $this->totais();

        $this->assertSame(1, $calculos);
        $this->assertSame('3000.00', $depois['original_amount']);
    }

    public function test_creating_invalidates_the_totals(): void
    {
        $cobranca = $this->cobranca();
        $this->totais();

        $this->postJson('/api/billings', [
            'customer_id' => $cobranca->customer_id,
            'description' => 'Nova cobrança',
            'original_amount' => '500.00',
            'monthly_interest_rate' => '0.0100',
            'issue_date' => '2026-06-01',
            'due_date' => '2026-07-01',
        ])->assertCreated();

        [$depois, $calculos] = $this->totais();

        $this->assertSame(1, $calculos);
        $this->assertSame(2, $depois['count']);
    }

    /** A importação grava por insert em lote, sem passar pelo Eloquent. */
    public function test_an_import_invalidates_the_totals(): void
    {
        $cliente = $this->cliente('33333333000133');
        $this->cobranca($cliente);
        $this->totais();

        $arquivo = UploadedFile::fake()->createWithContent(
            'cobrancas.csv',
            "documento;descricao;valor;emissao;vencimento\n"
            ."33333333000133;Importada;750,00;01/06/2026;01/07/2026\n",
        );

        $this->post('/api/billings/import', ['file' => $arquivo], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('imported_count', 1);

        [$depois, $calculos] = $this->totais();

        $this->assertSame(1, $calculos);
        $this->assertSame(2, $depois['count']);
    }

    /**
     * Os juros mudam com o dia, sem nenhuma escrita.
     *
     * Nenhuma invalidação por evento pega isto — não houve evento. É a data de
     * referência dentro da chave que faz o total de amanhã ser outro.
     */
    public function test_the_day_rolling_over_recomputes_the_interest(): void
    {
        $this->cobranca();
        [$hoje] = $this->totais();

        $this->travelTo('2026-06-16 09:30:00');

        [$amanha, $calculos] = $this->totais();

        $this->assertSame(1, $calculos);
        $this->assertSame('20.00', $hoje['interest_amount']);
        $this->assertSame('20.67', $amanha['interest_amount']);
    }

    // --- os outros consumidores ---------------------------------------

    /** O CSV imprime os totais no rodapé, e aproveita os que a tela já calculou. */
    public function test_the_csv_export_reuses_the_totals_from_the_screen(): void
    {
        $this->cobranca();
        $this->totais();

        $antes = $this->agregacoes;

        $this->get('/api/reports/billings/csv')->assertOk()->streamedContent();

        $this->assertSame($antes, $this->agregacoes);
    }
}
