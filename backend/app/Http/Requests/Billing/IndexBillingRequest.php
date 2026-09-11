<?php

namespace App\Http\Requests\Billing;

use App\Domain\Billing\BillingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexBillingRequest extends FormRequest
{
    public const SORTABLE = [
        'id', 'due_date', 'issue_date', 'original_amount', 'created_at',
    ];

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
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')],
            'status' => ['nullable', Rule::enum(BillingStatus::class)],
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'sort.in' => 'Ordenação inválida. Permitido: '.implode(', ', self::SORTABLE).'.',
            'per_page.max' => 'O máximo por página é '.self::MAX_PER_PAGE.'.',
        ];
    }
}
