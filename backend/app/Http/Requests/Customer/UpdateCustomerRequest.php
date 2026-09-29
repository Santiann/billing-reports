<?php

namespace App\Http\Requests\Customer;

use App\Domain\Customer\CustomerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The document arrives from the screen formatted and is stored as digits only: keeping
     * what was typed would make the search depend on the formatting.
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
                // A CPF has 11 digits, a CNPJ has 14. Nothing in between.
                'regex:/^(\d{11}|\d{14})$/',
                // Ignores the record itself: without this nobody could save an edit without
                // first changing the document.
                Rule::unique('customers', 'document')->ignore($this->route('customer')),
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
