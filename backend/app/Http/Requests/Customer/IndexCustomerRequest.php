<?php

namespace App\Http\Requests\Customer;

use App\Domain\Customer\CustomerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida os parâmetros de consulta da listagem.
 *
 * `sort` é validado contra allowlist porque ele entra no ORDER BY: aceitar o
 * valor cru seria injeção. E `per_page` tem teto porque sem ele
 * `?per_page=999999` derruba a API com uma requisição.
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
