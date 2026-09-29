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
 * The volume seeder's deferred indexes.
 *
 * This class does NOT use RefreshDatabase, and that is on purpose. What it tests is
 * DDL — derrubar e recriar índice —, e DDL faz commit implícito no MySQL.
 * Inside RefreshDatabase's transaction, that commit would end the transaction and make every
 * following test in the suite redo the migrations (trap 6 in the testing skill). Outside it, the
 * DDL breaks nothing.
 *
 * The price is looking after the state by hand, and here it is small: the tests only touch the
 * STRUCTURE of an empty table, and finish with the structure the same as at the start. Not a
 * single row is written.
 */
class BillingVolumeSeederIndexesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Without RefreshDatabase, nobody guarantees the schema exists if this class is the
        // first in the suite to touch the database.
        if (! Schema::hasTable('billings')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    /** @return array<string, array<int, string>> */
    private function indicesDeBillings(): array
    {
        $rows = DB::select(
            'SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            .'ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            ['billings'],
        );

        $indices = [];

        foreach ($rows as $row) {
            $indices[$row->INDEX_NAME][] = $row->COLUMN_NAME;
        }

        unset($indices['PRIMARY']);

        return $indices;
    }

    /**
     * The seeder's list is a copy of the migrations, and the copy is watched here.
     *
     * If someone creates an index in a migration and forgets the list, the seeder would drop seven
     * and recreate seven — and the eighth would vanish on the first load, with no error at all.
     */
    public function test_the_seeder_list_is_exactly_what_the_migrations_create(): void
    {
        $this->assertEqualsCanonicalizing(
            ReportIndexes::DEFINITIONS,
            $this->indicesDeBillings(),
        );
    }

    public function test_during_the_load_the_indexes_are_gone_and_the_foreign_key_still_has_support(): void
    {
        $durante = null;

        (new BillingVolumeSeeder())->withDeferredIndexes(function () use (&$durante): void {
            $durante = $this->indicesDeBillings();
        });

        foreach (array_keys(ReportIndexes::DEFINITIONS) as $nome) {
            $this->assertArrayNotHasKey($nome, $durante, "{$nome} ainda existia durante a carga.");
        }

        // customer_id's foreign key cannot be left without an index: the
        // MySQL recusaria o DROP.
        $this->assertSame(['customer_id'], $durante[ReportIndexes::FOREIGN_KEY_SUPPORT] ?? null);
    }

    public function test_after_the_load_the_structure_is_back_to_what_it_was(): void
    {
        $before = $this->indicesDeBillings();

        (new BillingVolumeSeeder())->withDeferredIndexes(fn () => null);

        $this->assertEqualsCanonicalizing($before, $this->indicesDeBillings());
        $this->assertArrayNotHasKey(ReportIndexes::FOREIGN_KEY_SUPPORT, $this->indicesDeBillings());
    }

    /**
     * The guarantee the brief asks for: if the load fails halfway, the indexes come back just the
     * same. Without it, whoever brought the application up afterwards would have a report scanning
     * the whole table, with no error pointing at the cause.
     */
    public function test_the_indexes_come_back_even_if_the_load_fails(): void
    {
        $before = $this->indicesDeBillings();

        try {
            (new BillingVolumeSeeder())->withDeferredIndexes(function (): void {
                throw new RuntimeException('Carga interrompida no meio.');
            });

            $this->fail('A exceção da carga deveria ter subido.');
        } catch (RuntimeException $error) {
            $this->assertSame('Carga interrompida no meio.', $error->getMessage());
        }

        $this->assertEqualsCanonicalizing($before, $this->indicesDeBillings());
    }
}
