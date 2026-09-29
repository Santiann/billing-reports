<?php

namespace App\Domain\Report;

use App\Domain\Billing\BillingDataVersion;
use App\Domain\Billing\BillingStatus;
use App\Domain\Billing\InterestCalculator;
use App\Models\Billing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the report's query and the totals' query.
 *
 * Both start from the SAME filters. That is what guarantees the report's footer talks
 * about the same set as the rows on display.
 */
final class BillingReportQuery
{
    private const CACHE_PREFIX = 'report-totals:';

    private readonly InterestCalculator $calculator;

    public function __construct(
        ?InterestCalculator $calculator = null,
        private readonly BillingDataVersion $version = new BillingDataVersion(),
    ) {
        $this->calculator = $calculator ?? new InterestCalculator();
    }

    /**
     * The report's rows, paginated by the caller.
     *
     * @return Builder<Billing>
     */
    public function rows(BillingReportFilters $filters): Builder
    {
        $query = Billing::query()->with('customer');

        $this->applyFilters($query, $filters);

        $query
            ->select('billings.*')
            ->selectRaw("{$this->calculator->updatedAmountSql()} as updated_amount")
            ->selectRaw("{$this->calculator->interestAmountSql()} as interest_amount");

        // Sorting by `updated_amount` is only possible because the value exists in SQL.
        // With the calculation in PHP alone, sorting by it would force loading the whole
        // set into memory.
        $query->orderBy($filters->sort, $filters->direction);

        // A stable tie-break: without it, two pages can repeat or skip rows when the
        // sorted column has ties.
        $query->orderBy('billings.id', 'asc');

        return $query;
    }

    /**
     * The totals, from cache while they are still valid.
     *
     * One entry per scope, and not one per version: the value stores the data version and
     * the reference date it was computed with, and is overwritten when either changes.
     * That way the cache table grows with the number of scopes queried, and not with the
     * number of writes — the database driver only deletes an expired entry when someone
     * reads it, and an abandoned key would sit there forever.
     *
     * The order of the two reads is the guarantee. The version is read BEFORE computing,
     * so the totals that get stored were computed over data at least as new as the version
     * accompanying them. If a write lands in between, the current version goes up and the
     * entry simply is not served. The reverse — stale data under a new version — cannot
     * happen.
     *
     * @return array<string, mixed>
     */
    public function totals(BillingReportFilters $filters): array
    {
        $currentVersion = $this->version->current();
        $data = $this->calculator->referenceDate()->toDateString();
        $key = self::CACHE_PREFIX.hash('sha256', (string) json_encode($filters->scope()));

        $kept = Cache::get($key);

        if (is_array($kept) && $kept['version'] === $currentVersion && $kept['date'] === $data) {
            return $kept['totals'];
        }

        $totals = $this->computeTotals($filters);

        Cache::put($key, ['version' => $currentVersion, 'date' => $data, 'totals' => $totals], now()->addDay());

        return $totals;
    }

    /**
     * Totals over the WHOLE filtered set, bypassing the cache.
     *
     * A separate aggregation query, never the sum of the current page: the user on page 3
     * needs to see the report's total, not the page's.
     *
     * Public because the `report:explain` command needs the query, not the result: if it
     * called `totals()`, the cache would hide the aggregation from it — the report's most
     * expensive query, and the command's whole reason to exist.
     *
     * @return array<string, mixed>
     */
    public function computeTotals(BillingReportFilters $filters): array
    {
        $query = Billing::query();

        $this->applyFilters($query, $filters);

        $updated = $this->calculator->updatedAmountSql();
        $interest = $this->calculator->interestAmountSql();
        $paid = BillingStatus::Paid->value;

        // Received comes from the frozen columns; pending, from the updated amount.
        $received = "CASE WHEN billings.status = '{$paid}' THEN COALESCE(billings.paid_amount, 0) ELSE 0 END";
        $pending = "CASE WHEN billings.status = '{$paid}' THEN 0 ELSE {$updated} END";

        $row = $query->selectRaw(
            'COUNT(*) as total_count,'
            .' COALESCE(SUM(billings.original_amount), 0) as original_amount,'
            ." COALESCE(SUM({$interest}), 0) as interest_amount,"
            ." COALESCE(SUM({$updated}), 0) as updated_amount,"
            ." COALESCE(SUM({$received}), 0) as paid_amount,"
            ." COALESCE(SUM({$pending}), 0) as pending_amount",
        )->first();

        return [
            'count' => (int) ($row->total_count ?? 0),
            'original_amount' => $this->money($row->original_amount ?? 0),
            'interest_amount' => $this->money($row->interest_amount ?? 0),
            'updated_amount' => $this->money($row->updated_amount ?? 0),
            'paid_amount' => $this->money($row->paid_amount ?? 0),
            'pending_amount' => $this->money($row->pending_amount ?? 0),
        ];
    }

    /**
     * @param  Builder<Billing>  $query
     */
    private function applyFilters(Builder $query, BillingReportFilters $filters): void
    {
        // The column name comes from an allowlist, never raw from the request.
        $dateColumn = "billings.{$filters->dateField}";

        // A direct comparison, not whereDate(): wrapping the column in DATE() stops MySQL from
        // using the index, and the report is precisely where that cannot happen. The columns are
        // already of type DATE.
        if ($filters->startDate !== null) {
            $query->where($dateColumn, '>=', $filters->startDate);
        }

        if ($filters->endDate !== null) {
            $query->where($dateColumn, '<=', $filters->endDate);
        }

        if ($filters->customerId !== null) {
            $query->where('billings.customer_id', $filters->customerId);
        }

        match ($filters->status) {
            'paid' => $query->where('billings.status', BillingStatus::Paid->value),
            'pending' => $query->where('billings.status', BillingStatus::Pending->value),
            // Derived, and coming from the same source as the interest rule.
            'overdue' => $query->whereRaw($this->calculator->overdueSql()),
            default => null,
        };
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
