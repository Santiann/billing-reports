<?php

namespace App\Http\Requests\Billing;

use App\Domain\Billing\BillingStatus;
use App\Models\Billing;
use Illuminate\Contracts\Validation\Validator;

class UpdateBillingRequest extends StoreBillingRequest
{
    /**
     * A paid billing is immutable.
     *
     * Changing the amount or the rate after payment would invalidate `paid_amount` and
     * `paid_interest_amount`, which were frozen at the payment date and are not
     * recomputable — the calculation is a function of that date.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $billing = $this->route('billing');

            if ($billing instanceof Billing && $billing->status === BillingStatus::Paid) {
                $validator->errors()->add(
                    'status',
                    'Uma cobrança paga não pode ser editada.',
                );
            }
        });
    }
}
