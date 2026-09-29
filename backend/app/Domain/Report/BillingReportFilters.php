<?php

namespace App\Domain\Report;

/**
 * The report's filters, already validated.
 *
 * It exists as an object rather than a loose array because three consumers need exactly the
 * same scope: the screen, the CSV export and the PDF export. If each built its own, a filter
 * applied on the screen might not hold in the exported file — and the test requires the
 * exports to respect the filters.
 */
final class BillingReportFilters
{
    /** The user chooses which of the three dates defines the period. */
    public const DATE_FIELDS = ['issue_date', 'due_date', 'payment_date'];

    /** `overdue` is not a stored status: it is a derived condition. */
    public const STATUSES = ['pending', 'paid', 'overdue'];

    public const SORTABLE = [
        'issue_date', 'due_date', 'payment_date',
        'original_amount', 'interest_amount', 'updated_amount',
    ];

    public function __construct(
        public readonly string $dateField = 'due_date',
        public readonly ?string $startDate = null,
        public readonly ?string $endDate = null,
        public readonly ?int $customerId = null,
        public readonly ?string $status = null,
        public readonly string $sort = 'due_date',
        public readonly string $direction = 'desc',
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            dateField: self::pick($input, 'date_field', self::DATE_FIELDS) ?? 'due_date',
            startDate: isset($input['start_date']) ? (string) $input['start_date'] : null,
            endDate: isset($input['end_date']) ? (string) $input['end_date'] : null,
            customerId: isset($input['customer_id']) ? (int) $input['customer_id'] : null,
            status: self::pick($input, 'status', self::STATUSES),
            sort: self::pick($input, 'sort', self::SORTABLE) ?? 'due_date',
            direction: self::pick($input, 'direction', ['asc', 'desc']) ?? 'desc',
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $allowed
     */
    private static function pick(array $input, string $key, array $allowed): ?string
    {
        $value = isset($input[$key]) ? (string) $input[$key] : null;

        // A second barrier beyond the FormRequest: these values become column names in SQL,
        // and depending on a single layer for that is fragile.
        return $value !== null && in_array($value, $allowed, true) ? $value : null;
    }

    public function dateFieldLabel(): string
    {
        return match ($this->dateField) {
            'issue_date' => 'Data de emissão',
            'payment_date' => 'Data de pagamento',
            default => 'Data de vencimento',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pendente',
            'paid' => 'Paga',
            'overdue' => 'Vencida',
            default => 'Todos',
        };
    }

    /**
     * What defines the SET, and therefore the totals.
     *
     * Sorting and direction are left out: they change the order of the rows, not which rows are
     * in. Recomputing the totals on every sort click would waste the cache on the screen's most
     * common use.
     *
     * @return array<string, mixed>
     */
    public function scope(): array
    {
        return [
            'date_field' => $this->dateField,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'customer_id' => $this->customerId,
            'status' => $this->status,
        ];
    }

    /**
     * An echo for the screen and for the exported files' header.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date_field' => $this->dateField,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'customer_id' => $this->customerId,
            'status' => $this->status,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ];
    }
}
