<?php

namespace Database\Seeders;

use App\Domain\Billing\BillingDataVersion;
use App\Domain\Billing\BillingStatus;
use App\Domain\Billing\RegisterPayment;
use App\Domain\Customer\CustomerStatus;
use App\Models\Billing;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generates real volume to measure the report against.
 *
 * It does not run from DatabaseSeeder: this is millions of rows and several
 * minutes. Invoke it explicitly:
 *
 *     docker compose exec php php artisan db:seed --class=BillingVolumeSeeder
 *
 * The total is configurable by env so a smaller sample is possible:
 *
 *     docker compose exec -e BILLING_SEED_COUNT=100000 php \
 *         php artisan db:seed --class=BillingVolumeSeeder
 *
 * Batch inserts, never a factory row by row: the factory instantiates a model,
 * fires events and issues one INSERT per row. Over two million billings the
 * difference is not a percentage, it is an order of magnitude.
 *
 * Some of the paid ones are paid LATE, with genuinely frozen interest. Without
 * that the measurement base does not exercise the freezing rule: the report
 * would show zero interest received and the detail screen would never have
 * anything to display. What computes the frozen amount is RegisterPayment, the
 * same service the API uses — the batch insert stays, what changes is where the
 * number comes from.
 */
class BillingVolumeSeeder extends Seeder
{
    private const CUSTOMERS = 5_000;

    /** The share of billings that are born paid. */
    private const PAID_PERCENT = 40;

    /** The share OF THE PAID ONES paid late, and therefore with interest. */
    private const PAID_LATE_PERCENT = 35;

    /**
     * The cap on payment lateness, in days.
     *
     * Without a cap, a billing three years overdue and paid today at 5% a month
     * would accrue 1.05^36 — nearly six times the original amount. It happens, but
     * it is not what a billing base looks like.
     */
    private const MAX_DAYS_LATE = 120;

    /** The maximum earliness of an on-time payment, in days. */
    private const MAX_DAYS_EARLY = 25;

    /** Rows per INSERT. Above this max_allowed_packet starts to bite. */
    private const CHUNK = 2_000;

    /**
     * The billing count from which the indexes are dropped before loading.
     *
     * An index does not speed an insert up, it slows it down: every row maintains
     * eight more trees. On a large load, dropping and recreating at the end is
     * cheaper — the measurement is in docs/performance.md. On a small load it does
     * not pay off, and there is a stronger reason not to: the seeder's test seeds a
     * sample, and DDL inside a test ends RefreshDatabase's transaction through an
     * implicit commit, making every following test in the suite redo the migrations.
     */
    private const DEFER_INDEXES_FROM = 100_000;

    /**
     * The total also comes in through the constructor, not only through env, so the
     * test can seed a small sample: overriding `env()` from inside the test would
     * touch the whole process's environment.
     */
    public function __construct(private readonly ?int $total = null) {}

    public function run(): void
    {
        $total = $this->total ?? (int) (env('BILLING_SEED_COUNT') ?: 2_000_000);

        // A sample of 600 billings spread over 5,000 customers looks like nothing:
        // almost every customer would end up with zero or one billing. From 100,000
        // on the cap applies and the real load is unchanged.
        $customers = max(1, min(self::CUSTOMERS, intdiv($total, 20)));

        // Without this Laravel keeps every INSERT in memory and the process dies of
        // exhaustion long before the end.
        DB::connection()->disableQueryLog();

        $this->command?->info(sprintf(
            'Gerando %s clientes e %s cobranças…',
            number_format($customers, 0, ',', '.'),
            number_format($total, 0, ',', '.'),
        ));

        $startedAt = microtime(true);

        $this->truncate();

        // Healing a previous run that was killed: if it died with the indexes
        // dropped, they come back now — with the table freshly truncated, recreating
        // them is instant. With nothing missing, this is just a read.
        $this->recreateMissingIndexes();

        $this->seedCustomers($customers);
        $customerIds = DB::table('customers')->pluck('id')->all();

        if ($total >= self::DEFER_INDEXES_FROM) {
            $this->withDeferredIndexes(fn () => $this->seedBillings($total, $customerIds));
        } else {
            $this->seedBillings($total, $customerIds);
        }

        // A raw batch insert does not go through the observer. Without this, a total
        // cached from before the load would keep being served after it.
        app(BillingDataVersion::class)->bump();

        $this->command?->info(sprintf(
            'Concluído em %s.',
            $this->humanize(microtime(true) - $startedAt),
        ));
    }

    /**
     * Starts from scratch on every run.
     *
     * The documents are sequential to guarantee uniqueness without querying the
     * database, which makes a second run impossible over the first one's data. And
     * measuring a query over volume accumulated from previous runs would say
     * nothing — the point is for the volume to be known.
     */
    private function truncate(): void
    {
        // TRUNCATE is DDL and costs seconds even on an empty table. Bailing out early
        // when there is nothing to clear removes that cost from every test in the
        // suite — and, as a bonus, preserves RefreshDatabase's transaction, which a
        // TRUNCATE would end through an implicit commit.
        if (! DB::table('billings')->exists() && ! DB::table('customers')->exists()) {
            return;
        }

        Schema::disableForeignKeyConstraints();
        // The trail goes with it: the TRUNCATE restarts the billings' ids, and the
        // old trail would end up describing billings that are not its own.
        DB::table('billing_audits')->truncate();
        DB::table('billings')->truncate();
        DB::table('customers')->truncate();
        Schema::enableForeignKeyConstraints();
    }

    /**
     * Runs the load with the secondary indexes dropped, and recreates them after.
     *
     * The recreation sits in a `finally`: if the load fails halfway, the table is not
     * left without indexes — whoever brought the application up next would get a
     * report scanning two million rows on every query, with no error pointing at the
     * cause.
     *
     * The `finally` does not cover a process that gets killed. That case is what
     * `recreateMissingIndexes()` at the start of `run()` heals.
     *
     * Public so the test can exercise the guarantee with a load that fails.
     */
    public function withDeferredIndexes(callable $load): void
    {
        $startedAt = microtime(true);
        $this->ensureForeignKeySupport();
        $this->dropIndexes();
        $this->command?->info(sprintf('  índices derrubados em %s', $this->humanize(microtime(true) - $startedAt)));

        try {
            $load();
        } finally {
            $startedAt = microtime(true);
            $this->recreateMissingIndexes();
            $this->removeForeignKeySupport();
            $this->command?->info(sprintf('  índices recriados em %s', $this->humanize(microtime(true) - $startedAt)));
        }
    }

    /** @return array<int, string> */
    private function existingIndexes(): array
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'billings')
            ->distinct()
            ->pluck('INDEX_NAME')
            ->all();
    }

    private function ensureForeignKeySupport(): void
    {
        if (! in_array(ReportIndexes::FOREIGN_KEY_SUPPORT, $this->existingIndexes(), true)) {
            DB::statement(sprintf(
                'ALTER TABLE billings ADD INDEX %s (customer_id)',
                ReportIndexes::FOREIGN_KEY_SUPPORT,
            ));
        }
    }

    private function dropIndexes(): void
    {
        $present = array_intersect(array_keys(ReportIndexes::DEFINITIONS), $this->existingIndexes());

        if ($present === []) {
            return;
        }

        // A single statement: each separate ALTER would take the table's metadata
        // lock again.
        DB::statement('ALTER TABLE billings '.implode(', ', array_map(
            fn (string $name) => "DROP INDEX {$name}",
            $present,
        )));
    }

    /**
     * Creates whatever is missing, one ALTER per index.
     *
     * Idempotent on purpose: it is called both from the load's `finally` and from the
     * healing at the start, and in both cases it may find some of the indexes already
     * standing.
     *
     * One ALTER per index, and not a single one with all of them — and the first
     * version of this method did the opposite, with a comment claiming it was cheaper.
     * Measuring against the 2,000,000 rows at rest disproved it: all eight in a single
     * ALTER took 19min12s, and one by one, 10min44s.
     *
     * MySQL's manual documents that the DDL buffer is split between DDL threads; how
     * it divides between several indexes built in the same statement, it does not
     * document. The number decides the implementation — the explanation stays open.
     */
    private function recreateMissingIndexes(): void
    {
        $missing = array_diff_key(ReportIndexes::DEFINITIONS, array_flip($this->existingIndexes()));

        foreach ($missing as $name => $columns) {
            DB::statement(sprintf(
                'ALTER TABLE billings ADD INDEX %s (%s)',
                $name,
                implode(', ', $columns),
            ));
        }
    }

    /**
     * Drops the temporary index — and only if the indexes starting with `customer_id`
     * are already back. If the recreation failed, the temporary one stays: the foreign
     * key cannot be left without support.
     */
    private function removeForeignKeySupport(): void
    {
        $existing = $this->existingIndexes();

        $supported = array_filter(
            ReportIndexes::DEFINITIONS,
            fn (array $columns, string $name) => $columns[0] === 'customer_id' && in_array($name, $existing, true),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($supported !== [] && in_array(ReportIndexes::FOREIGN_KEY_SUPPORT, $existing, true)) {
            DB::statement(sprintf('ALTER TABLE billings DROP INDEX %s', ReportIndexes::FOREIGN_KEY_SUPPORT));
        }
    }

    private function seedCustomers(int $customers): void
    {
        $now = now();
        $rows = [];

        for ($i = 1; $i <= $customers; $i++) {
            $rows[] = [
                'name' => "Cliente {$i}",
                // Sequential and not random: it guarantees uniqueness with no
                // collision and without querying the database on every row.
                'document' => str_pad((string) $i, 11, '0', STR_PAD_LEFT),
                'email' => "cliente{$i}@exemplo.test",
                'status' => $i % 20 === 0
                    ? CustomerStatus::Inactive->value
                    : CustomerStatus::Active->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= self::CHUNK) {
                DB::table('customers')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('customers')->insert($rows);
        }
    }

    /**
     * @param  array<int, int>  $customerIds
     */
    private function seedBillings(int $total, array $customerIds): void
    {
        $now = now();
        $today = now()->startOfDay();
        $lastCustomer = count($customerIds) - 1;

        // Faker is far too slow for millions of rows: drawing from a small pool
        // produces enough data to measure queries and indexes.
        $descriptions = [
            'Mensalidade', 'Serviço prestado', 'Licença de uso',
            'Consultoria', 'Suporte técnico', 'Hospedagem',
        ];
        $rates = ['0.0100', '0.0200', '0.0350', '0.0500'];

        $registerPayment = app(RegisterPayment::class);

        $rows = [];
        $inserted = 0;

        for ($i = 0; $i < $total; $i++) {
            // Issue dates spread over three years so the period filter has something
            // to narrow.
            $issueDate = $today->copy()->subDays(mt_rand(0, 1_095));
            $dueDate = $issueDate->copy()->addDays(30);
            $amount = mt_rand(10_000, 1_000_000) / 100;
            $rate = $rates[mt_rand(0, 3)];

            $payment = mt_rand(1, 100) <= self::PAID_PERCENT
                ? $this->freezePayment($registerPayment, $amount, $rate, $dueDate, $today)
                : null;

            $rows[] = [
                'customer_id' => $customerIds[mt_rand(0, $lastCustomer)],
                'description' => $descriptions[mt_rand(0, 5)],
                'original_amount' => $amount,
                'monthly_interest_rate' => $rate,
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'payment_date' => $payment['payment_date'] ?? null,
                'status' => $payment['status'] ?? BillingStatus::Pending->value,
                'paid_amount' => $payment['paid_amount'] ?? null,
                'paid_interest_amount' => $payment['paid_interest_amount'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= self::CHUNK) {
                DB::table('billings')->insert($rows);
                $inserted += count($rows);
                $rows = [];

                if ($inserted % 100_000 === 0) {
                    $this->command?->info(sprintf(
                        '  %s / %s',
                        number_format($inserted, 0, ',', '.'),
                        number_format($total, 0, ',', '.'),
                    ));
                }
            }
        }

        if ($rows !== []) {
            DB::table('billings')->insert($rows);
        }
    }

    /**
     * Draws WHEN the billing was paid and returns the frozen columns.
     *
     * What computes the amount is RegisterPayment, the same service the API uses. This
     * method decides the date and nothing else: repeating the interest formula here
     * would make the seeder a second implementation of the rule, and the measurement
     * base would stop counting as proof of what the screen shows.
     *
     * Returns null when no payment date is possible — a billing falling due more than
     * MAX_DAYS_EARLY days from now has not been paid yet, because the payment would
     * land in the future.
     *
     * @return array<string, string>|null
     */
    private function freezePayment(
        RegisterPayment $registerPayment,
        float $amount,
        string $rate,
        CarbonInterface $dueDate,
        CarbonInterface $today,
    ): ?array {
        $daysOverdue = $dueDate->lt($today) ? (int) $dueDate->diffInDays($today) : 0;

        if ($daysOverdue > 0 && mt_rand(1, 100) <= self::PAID_LATE_PERCENT) {
            $paymentDate = $dueDate->copy()->addDays(
                mt_rand(1, min(self::MAX_DAYS_LATE, $daysOverdue)),
            );
        } else {
            // Paid on time. The floor on earliness is how long there is still to go
            // before it falls due: without it, a billing due next week would be paid
            // after today.
            $daysEarly = $daysOverdue > 0 ? 1 : (int) $today->diffInDays($dueDate);

            if ($daysEarly > self::MAX_DAYS_EARLY) {
                return null;
            }

            $paymentDate = $dueDate->copy()->subDays(
                mt_rand($daysEarly, self::MAX_DAYS_EARLY),
            );
        }

        // An unpersisted model: it only exists for the calculator to read the amount,
        // the rate and the due date. Persisting here would be back to one INSERT per
        // row.
        return $registerPayment->freeze(
            new Billing([
                'original_amount' => $amount,
                'monthly_interest_rate' => $rate,
                'due_date' => $dueDate->toDateString(),
            ]),
            $paymentDate,
        );
    }

    private function humanize(float $seconds): string
    {
        return $seconds < 60
            ? sprintf('%.1fs', $seconds)
            : sprintf('%dmin %ds', (int) ($seconds / 60), (int) $seconds % 60);
    }
}
