<?php

namespace App\Http\Requests\Billing;

use App\Domain\Billing\BillingStatus;
use App\Models\Billing;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ReversePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * O estorno não tem corpo.
     *
     * Um motivo seria o campo óbvio, e ficou de fora de propósito — o README
     * explica. O quem e o quando já estão na trilha.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $billing = $this->route('billing');

            if ($billing instanceof Billing && $billing->status !== BillingStatus::Paid) {
                $validator->errors()->add(
                    'status',
                    'Só uma cobrança paga pode ser estornada.',
                );
            }
        });
    }
}
