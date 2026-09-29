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

        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'total_count')) {
                $this->agregacoes++;
            }
        });
    }

    private function customer(string $documento = '12345678000190'): Customer
    {
        return Customer::factory()->create(['document' => $documento]);
    }

    /** Vencida há 30 dias a 2% ao mês: 1.020,00 hoje. */
    private function billing(?Customer $cliente = null): Billing
    {
        return Billing::factory()->create([
            'customer_id' => ($cliente ?? $this->customer())->id,
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);
    }

    /**
     * Totais do relatório, e quantas agregações a chamada disparou.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function totals(array $filters = []): array
    {
        $before = $this->agregacoes;

        $totals = $this->getJson('/api/reports/billings?'.http_build_query($filters))
            ->assertOk()
            ->json('totals');

        return [$totals, $this->agregacoes - $before];
    }

    // --- o cache serve ------------------------------------------------

    public function test_a_second_query_with_the_same_filters_does_not_recompute(): void
    {
        $this->billing();

        [$firstOne, $calculos1] = $this->totals();
        [$segunda, $calculos2] = $this->totals();

        $this->assertSame(1, $calculos1);
        $this->assertSame(0, $calculos2);
        $this->assertSame($firstOne, $segunda);
    }

    /**
     * Ordenação e página mudam as linhas, não o conjunto: os totais são os
     * mesmos, e recalculá-los a cada clique de ordenação desperdiçaria o cache
     * justamente no uso mais comum da tela.
     */
    public function test_sorting_and_paginating_reuse_the_totals(): void
    {
        $this->billing();
        $this->totals();

        [, $calculos] = $this->totals(['sort' => 'original_amount', 'direction' => 'asc', 'page' => 2]);

        $this->assertSame(0, $calculos);
    }

    public function test_different_filters_do_not_share_totals(): void
    {
        $a = $this->customer('11111111000111');
        $b = $this->customer('22222222000122');
        $this->billing($a);
        $this->billing($b);
        $this->billing($b);

        [$totaisA] = $this->totals(['customer_id' => $a->id]);
        [$totaisB, $calculos] = $this->totals(['customer_id' => $b->id]);

        $this->assertSame(1, $calculos);
        $this->assertSame(1, $totaisA['count']);
        $this->assertSame(2, $totaisB['count']);
    }

    /** O que sai do cache é exatamente o que a consulta calcularia. */
    public function test_the_cached_totals_match_the_queried_ones(): void
    {
        $this->billing();
        Billing::factory()->paidLate(45)->create();

        [$doCache] = $this->totals();
        [$outraVez] = $this->totals();

        Cache::flush();
        [$recalculados, $calculos] = $this->totals();

        $this->assertSame(1, $calculos);
        $this->assertSame($recalculados, $doCache);
        $this->assertSame($recalculados, $outraVez);
    }

    // --- o cache erra na hora certa -----------------------------------

    public function test_a_payment_invalidates_the_totals(): void
    {
        $billing = $this->billing();
        [$before] = $this->totals();

        $this->postJson("/api/billings/{$billing->id}/payment")->assertOk();

        [$after, $calculos] = $this->totals();

        $this->assertSame(1, $calculos);
        $this->assertSame('0.00', $before['paid_amount']);
        $this->assertSame('1020.00', $after['paid_amount']);
    }

    public function test_a_reversal_invalidates_the_totals(): void
    {
        $billing = $this->billing();
        $this->postJson("/api/billings/{$billing->id}/payment")->assertOk();
        [$before] = $this->totals();

        $this->postJson("/api/billings/{$billing->id}/reversal")->assertOk();

        [$after, $calculos] = $this->totals();

        $this->assertSame(1, $calculos);
        $this->assertSame('1020.00', $before['paid_amount']);
        $this->assertSame('0.00', $after['paid_amount']);
    }

    public function test_an_edit_invalidates_the_totals(): void
    {
        $billing = $this->billing();
        $this->totals();

        $this->putJson("/api/billings/{$billing->id}", [
            'customer_id' => $billing->customer_id,
            'description' => $billing->description,
            'original_amount' => '2000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ])->assertOk();

        [$after, $calculos] = $this->totals();

        $this->assertSame(1, $calculos);
        $this->assertSame('2000.00', $after['original_amount']);
    }

    /** Alteração fora de requisição também invalida: ela passa pela trilha. */
    public function test_a_change_from_the_console_invalidates_the_totals(): void
    {
        $billing = $this->billing();
        $this->totals();

        $billing->update(['original_amount' => '3000.00']);

        [$after, $calculos] = $this->totals();

        $this->assertSame(1, $calculos);
        $this->assertSame('3000.00', $after['original_amount']);
    }

    public function test_creating_invalidates_the_totals(): void
    {
        $billing = $this->billing();
        $this->totals();

        $this->postJson('/api/billings', [
            'customer_id' => $billing->customer_id,
            'description' => 'Nova cobrança',
            'original_amount' => '500.00',
            'monthly_interest_rate' => '0.0100',
            'issue_date' => '2026-06-01',
            'due_date' => '2026-07-01',
        ])->assertCreated();

        [$after, $calculos] = $this->totals();

        $this->assertSame(1, $calculos);
        $this->assertSame(2, $after['count']);
    }

    /** A importação grava por insert em lote, sem passar pelo Eloquent. */
    public function test_an_import_invalidates_the_totals(): void
    {
        $cliente = $this->customer('33333333000133');
        $this->billing($cliente);
        $this->totals();

        $file = UploadedFile::fake()->createWithContent(
            'cobrancas.csv',
            "documento;descricao;valor;emissao;vencimento\n"
            ."33333333000133;Importada;750,00;01/06/2026;01/07/2026\n",
        );

        $this->post('/api/billings/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('imported_count', 1);

        [$after, $calculos] = $this->totals();

        $this->assertSame(1, $calculos);
        $this->assertSame(2, $after['count']);
    }

    /**
     * Os juros mudam com o dia, sem nenhuma escrita.
     *
     * Nenhuma invalidação por evento pega isto — não houve evento. É a data de
     * referência dentro da chave que faz o total de amanhã ser outro.
     */
    public function test_the_day_rolling_over_recomputes_the_interest(): void
    {
        $this->billing();
        [$today] = $this->totals();

        $this->travelTo('2026-06-16 09:30:00');

        [$amanha, $calculos] = $this->totals();

        $this->assertSame(1, $calculos);
        $this->assertSame('20.00', $today['interest_amount']);
        $this->assertSame('20.67', $amanha['interest_amount']);
    }

    // --- os outros consumidores ---------------------------------------

    /** O CSV imprime os totais no rodapé, e aproveita os que a tela já calculou. */
    public function test_the_csv_export_reuses_the_totals_from_the_screen(): void
    {
        $this->billing();
        $this->totals();

        $before = $this->agregacoes;

        $this->get('/api/reports/billings/csv')->assertOk()->streamedContent();

        $this->assertSame($before, $this->agregacoes);
    }
}
