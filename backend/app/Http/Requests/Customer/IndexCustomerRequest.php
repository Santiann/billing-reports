<?php

namespace App\Http\Requests\Customer;

use App\Domain\Customer\CustomerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the listing's query parameters.
 *
 * `sort` is validated against an allowlist because it goes into the ORDER BY: accepting the
 * raw value would be injection. And `per_page` is capped because without it
 * `?per_page=999999` takes the API down with a single request.
 */
class IndexCustomerRequest extends FormRequest
{
    public const SORTABLE = ['name', 'document', 'email', 'created_at'];

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
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(CustomerStatus::class)],
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
