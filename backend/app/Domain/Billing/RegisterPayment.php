<?php

namespace App\Domain\Billing;

use App\Models\Billing;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Congela os juros no ato do pagamento.
 *
 * Os juros são calculados na DATA DO PAGAMENTO, não em hoje: pagar uma
 * cobrança com data retroativa tem que produzir o valor daquele dia. É por
 * isso que o InterestCalculator aceita uma data de referência.
 *
 * Depois daqui a cobrança para de acumular: o valor exibido vem das colunas
 * gravadas, e o InterestCalculator devolve elas em vez de recalcular.
 */
final class RegisterPayment
{
    public function __invoke(
        Billing $billing,
        CarbonInterface|string|null $paymentDate = null,
        ?string $paidAmount = null,
    ): Billing {
        $date = CarbonImmutable::parse(
            $paymentDate ?? CarbonImmutable::now(),
        )->startOfDay();

        $calculation = (new InterestCalculator($date))->for($billing);

        $billing->update([
            'status' => BillingStatus::Paid,
            'payment_date' => $date,
            'paid_interest_amount' => $calculation->interestAmount,
            // O valor efetivamente pago pode diferir do calculado (acordo,
            // desconto). Quando não informado, assume-se o valor atualizado.
            'paid_amount' => $paidAmount ?? $calculation->updatedAmount,
        ]);

        return $billing;
    }
}
