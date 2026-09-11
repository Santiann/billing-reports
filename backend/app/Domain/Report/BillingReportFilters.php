<?php

namespace App\Domain\Report;

/**
 * Os filtros do relatório, já validados.
 *
 * Existe como objeto e não como array solto porque três consumidores precisam
 * exatamente do mesmo recorte: a tela, a exportação CSV e a exportação PDF.
 * Se cada uma montasse o seu, um filtro aplicado na tela poderia não valer no
 * arquivo exportado — e o teste exige que as exportações respeitem os filtros.
 */
final class BillingReportFilters
{
    /** O usuário escolhe qual das três datas define o período. */
    public const DATE_FIELDS = ['issue_date', 'due_date', 'payment_date'];

    /** `overdue` não é status gravado: é condição derivada. */
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

        // Segunda barreira além do FormRequest: estes valores viram nome de
        // coluna em SQL, e depender de uma camada só para isso é frágil.
        return $value !== null && in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * Eco para a tela e para o cabeçalho dos arquivos exportados.
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
