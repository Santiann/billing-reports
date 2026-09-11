<?php

namespace App\Http\Requests\Billing;

use App\Domain\Billing\BillingStatus;
use App\Models\Billing;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class RegisterPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $billing = $this->route('billing');

        return [
            'payment_date' => [
                'nullable',
                'date',
                // Não pode ser no futuro: juros são função da data, e aceitar
                // data futura gravaria um congelamento que ainda não ocorreu.
                'before_or_equal:today',
                ...($billing instanceof Billing
                    ? ['after_or_equal:'.$billing->issue_date->toDateString()]
                    : []),
            ],
            'paid_amount' => ['nullable', 'numeric', 'min:0.01', 'max:9999999999.99'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $billing = $this->route('billing');

            if ($billing instanceof Billing && $billing->status === BillingStatus::Paid) {
                $validator->errors()->add(
                    'status',
                    'Esta cobrança já está paga.',
                );
            }
        });
    }
}
