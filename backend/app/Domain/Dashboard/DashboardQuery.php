<?php

namespace App\Domain\Dashboard;

use App\Domain\Billing\BillingStatus;
use App\Domain\Billing\InterestCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's two queries.
 *
 * Everything aggregates in the database. Neither one brings rows into PHP to be
 * summed — over two million billings that would not be slow, it would be
 * impossible.
 *
 * Interest comes from InterestCalculator, as everywhere else: the dashboard
 * cannot disagree with the report about the same scope, and the only way to
 * guarantee that is to not have a second formula.
 */
final class DashboardQuery
{
    /** Months shown in the series, including the current one. */
    private const MONTHS = 12;

    private readonly CarbonImmutable $today;

    private readonly InterestCalculator $calculator;

    public function __construct(CarbonInterface|string|null $today = null)
    {
        $this->today = $today === null
            ? CarbonImmutable::now()->startOfDay()
            : CarbonImmutable::parse($today)->startOfDay();

        $this->calculator = new InterestCalculator($this->today);
    }

    /**
     * Indicators for the current month, scoped by DUE DATE.
     *
     * Due date and not issue date: what matters to whoever opens the system is
     * what falls due now — what came in, what is still to come in, and what has
     * already gone past due.
     *
     * @return array<string, mixed>
     */
    public function period(): array
    {
        $start = $this->today->startOfMonth();
        $end = $start->addMonth();

        $pending = BillingStatus::Pending->value;
        $updated = $this->calculator->updatedAmountSql();

        /*
         * The updated amount is computed ONCE per row, in a derived table, and
         * interest comes out of it by subtraction.
         *
         * The direct version summed `updatedAmountSql()` and
         * `interestAmountSql()` side by side, and both carry the same POW —
         * MySQL ran it twice on each of the month's 55,000 rows. Measured:
         * 0.87s against 0.45s.
         *
         * The subtraction holds because the sum only covers PENDING, and for a
         * pending billing interest is exactly updated amount minus original. It
         * would not hold for a paid one — there interest is the frozen column —
         * which is why a paid billing enters as zero.
         */
        $row = DB::table(DB::raw('('
            .'SELECT original_amount, paid_amount,'
            ." CASE WHEN status = '{$pending}' THEN {$updated} ELSE NULL END AS receivable,"
            ." {$this->calculator->overdueSql()} AS overdue"
            .' FROM billings'
            .' WHERE due_date >= ? AND due_date < ?'
            .') AS month'))
            ->setBindings([$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                'COUNT(*) AS total,'
                .' COALESCE(SUM(original_amount), 0) AS original,'
                // Received comes from the frozen column, never from a recompute.
                .' COALESCE(SUM(paid_amount), 0) AS received,'
                .' COALESCE(SUM(receivable), 0) AS receivable,'
                .' COALESCE(SUM(receivable - original_amount), 0) AS interest,'
                .' COALESCE(SUM(overdue), 0) AS overdue_count',
            )
            ->first();

        return [
            'label' => $this->monthLabel($start),
            'start_date' => $start->toDateString(),
            'end_date' => $end->subDay()->toDateString(),
            'count' => (int) $row->total,
            'original_amount' => $this->money($row->original),
            'received_amount' => $this->money($row->received),
            'pending_amount' => $this->money($row->receivable),
            'interest_amount' => $this->money($row->interest),
            'overdue_count' => (int) $row->overdue_count,
        ];
    }

    /**
     * Billed and received over the last twelve months, by due date.
     *
     * Twelve narrow ranges joined by UNION ALL, not a GROUP BY over the whole
     * year. The difference is not stylistic, it is an order of magnitude —
     * measured against 2,000,000 billings:
     *
     *     GROUP BY DATE_FORMAT(due_date, '%Y-%m')  ->  1.75s
     *     twelve ranges in UNION ALL               ->  0.33s
     *
     * The reason is in the EXPLAIN: the function over the column stops MySQL
     * from grouping in index order, and it builds a temporary table with the
     * whole year (`Using temporary`). Each isolated range is a simple range the
     * covering index answers without touching the table.
     *
     * `SUM(paid_amount)` sums directly, without filtering by status, because a
     * paid amount only exists on a paid billing — both forms give the same
     * number and this one is shorter. The equivalence is not an assumption:
     * there is a test asserting, in both directions, that no row has a paid
     * amount without being paid nor the other way round.
     *
     * @return array<int, array<string, mixed>>
     */
    public function monthly(): array
    {
        $first = $this->today->startOfMonth()->subMonths(self::MONTHS - 1);

        $parts = [];
        $values = [];

        for ($i = 0; $i < self::MONTHS; $i++) {
            $month = $first->addMonths($i);

            $parts[] = 'SELECT ? AS month, COUNT(*) AS total,'
                .' COALESCE(SUM(original_amount), 0) AS original,'
                .' COALESCE(SUM(paid_amount), 0) AS received'
                .' FROM billings WHERE due_date >= ? AND due_date < ?';

            $values[] = $month->format('Y-m');
            $values[] = $month->toDateString();
            $values[] = $month->addMonth()->toDateString();
        }

        $rows = DB::select(implode(' UNION ALL ', $parts), $values);

        return array_map(fn (object $row) => [
            'month' => $row->month,
            'label' => $this->shortLabel($row->month),
            'count' => (int) $row->total,
            'original_amount' => $this->money($row->original),
            'received_amount' => $this->money($row->received),
        ], $rows);
    }

    private function monthLabel(CarbonImmutable $month): string
    {
        $months = [
            1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
            'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
        ];

        return $months[$month->month].' de '.$month->year;
    }

    /** "2026-09" becomes "set/26", which is what fits under a bar. */
    private function shortLabel(string $month): string
    {
        $short = [
            1 => 'jan', 'fev', 'mar', 'abr', 'mai', 'jun',
            'jul', 'ago', 'set', 'out', 'nov', 'dez',
        ];

        [$year, $number] = explode('-', $month);

        return $short[(int) $number].'/'.substr($year, 2);
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
