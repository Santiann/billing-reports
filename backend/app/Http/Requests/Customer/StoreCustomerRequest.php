<?php

namespace App\Http\Requests\Customer;

use App\Domain\Customer\CustomerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Documento chega da tela com máscara e é gravado só com dígitos: guardar
     * o que foi digitado faria a busca depender do formato.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('document')) {
            $this->merge([
                'document' => preg_replace('/\D/', '', (string) $this->input('document')),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'document' => [
                'required',
                'string',
                // CPF tem 11 dígitos, CNPJ tem 14. Nada entre os dois.
                'regex:/^(\d{11}|\d{14})$/',
                Rule::unique('customers', 'document'),
            ],
            'email' => ['required', 'email', 'max:255'],
            'status' => ['required', Rule::enum(CustomerStatus::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'document.regex' => 'O documento deve ser um CPF (11 dígitos) ou CNPJ (14 dígitos).',
        ];
    }
}
