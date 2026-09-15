<?php

namespace Tests\Feature;

use Database\Seeders\BillingVolumeSeeder;
use Database\Seeders\ReportIndexes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Os índices adiados do seeder de volume.
 *
 * Esta classe NÃO usa RefreshDatabase, e é de propósito. O que ela testa é
 * DDL — derrubar e recriar índice —, e DDL faz commit implícito no MySQL.
 * Dentro da transação do RefreshDatabase, esse commit encerraria a transação e
 * faria cada teste seguinte da suíte refazer as migrations (armadilha 6 da
 * skill de testes). Fora dela, o DDL não quebra nada.
 *
 * O preço é cuidar do estado à mão, e aqui ele é pequeno: os testes só mexem
 * na ESTRUTURA de uma tabela vazia, e terminam com a estrutura igual à do
 * começo. Nenhuma linha é gravada.
 */
class BillingVolumeSeederIndexesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sem RefreshDatabase, ninguém garante que o schema exista se esta
        // classe for a primeira da suíte a tocar o banco.
        if (! Schema::hasTable('billings')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    /** @return array<string, array<int, string>> */
    private function indicesDeBillings(): array
    {
        $linhas = DB::select(
            'SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            .'ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            ['billings'],
        );

        $indices = [];

        foreach ($linhas as $linha) {
            $indices[$linha->INDEX_NAME][] = $linha->COLUMN_NAME;
        }

        unset($indices['PRIMARY']);

        return $indices;
    }

    /**
     * A lista do seeder é uma cópia das migrations, e a cópia é vigiada aqui.
     *
     * Se alguém criar um índice numa migration e esquecer da lista, o seeder
     * derrubaria sete e recriaria sete — e o oitavo sumiria na primeira carga,
     * sem erro nenhum.
     */
    public function test_a_lista_do_seeder_e_exatamente_o_que_as_migrations_criam(): void
    {
        $this->assertEqualsCanonicalizing(
            ReportIndexes::DEFINITIONS,
            $this->indicesDeBillings(),
        );
    }

    public function test_durante_a_carga_os_indices_nao_existem_e_a_chave_estrangeira_tem_apoio(): void
    {
        $durante = null;

        (new BillingVolumeSeeder())->withDeferredIndexes(function () use (&$durante): void {
            $durante = $this->indicesDeBillings();
        });

        foreach (array_keys(ReportIndexes::DEFINITIONS) as $nome) {
            $this->assertArrayNotHasKey($nome, $durante, "{$nome} ainda existia durante a carga.");
        }

        // A chave estrangeira de customer_id não pode ficar sem índice: o
        // MySQL recusaria o DROP.
        $this->assertSame(['customer_id'], $durante[ReportIndexes::FOREIGN_KEY_SUPPORT] ?? null);
    }

    public function test_depois_da_carga_a_estrutura_volta_a_ser_a_de_antes(): void
    {
        $antes = $this->indicesDeBillings();

        (new BillingVolumeSeeder())->withDeferredIndexes(fn () => null);

        $this->assertEqualsCanonicalizing($antes, $this->indicesDeBillings());
        $this->assertArrayNotHasKey(ReportIndexes::FOREIGN_KEY_SUPPORT, $this->indicesDeBillings());
    }

    /**
     * A garantia que o enunciado pede: se a carga falhar no meio, os índices
     * voltam do mesmo jeito. Sem isso, quem subisse a aplicação depois teria
     * um relatório varrendo a tabela inteira, sem erro que apontasse a causa.
     */
    public function test_os_indices_voltam_mesmo_se_a_carga_falhar(): void
    {
        $antes = $this->indicesDeBillings();

        try {
            (new BillingVolumeSeeder())->withDeferredIndexes(function (): void {
                throw new RuntimeException('Carga interrompida no meio.');
            });

            $this->fail('A exceção da carga deveria ter subido.');
        } catch (RuntimeException $erro) {
            $this->assertSame('Carga interrompida no meio.', $erro->getMessage());
        }

        $this->assertEqualsCanonicalizing($antes, $this->indicesDeBillings());
    }
}
