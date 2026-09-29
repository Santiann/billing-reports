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
     * A reversal has no body.
     *
     * A reason would be the obvious field, and it was deliberately left out — the README
     * explains. The who and the when are already in the trail.
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
