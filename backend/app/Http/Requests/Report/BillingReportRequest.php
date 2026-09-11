<?php

namespace App\Http\Requests\Report;

use App\Domain\Report\BillingReportFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillingReportRequest extends FormRequest
{
    private const MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date_field' => ['nullable', Rule::in(BillingReportFilters::DATE_FIELDS)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')],
            'status' => ['nullable', Rule::in(BillingReportFilters::STATUSES)],
            'sort' => ['nullable', Rule::in(BillingReportFilters::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_field.in' => 'A base do período deve ser emissão, vencimento ou pagamento.',
            'end_date.after_or_equal' => 'A data final não pode ser anterior à inicial.',
            'sort.in' => 'Ordenação inválida. Permitido: '.implode(', ', BillingReportFilters::SORTABLE).'.',
            'per_page.max' => 'O máximo por página é '.self::MAX_PER_PAGE.'.',
        ];
    }

    public function filters(): BillingReportFilters
    {
        return BillingReportFilters::fromArray($this->validated());
    }
}
