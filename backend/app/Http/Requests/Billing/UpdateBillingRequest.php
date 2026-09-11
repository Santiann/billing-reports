<?php

namespace App\Http\Requests\Billing;

use App\Domain\Billing\BillingStatus;
use App\Models\Billing;
use Illuminate\Contracts\Validation\Validator;

class UpdateBillingRequest extends StoreBillingRequest
{
    /**
     * Cobrança paga é imutável.
     *
     * Alterar valor ou taxa depois do pagamento invalidaria `paid_amount` e
     * `paid_interest_amount`, que foram congelados na data do pagamento e não
     * são recalculáveis — o cálculo é função da data de então.
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
