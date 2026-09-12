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
        $billing->update($this->freeze($billing, $paymentDate, $paidAmount));

        return $billing;
    }

    /**
     * As colunas que o pagamento grava, sem gravar.
     *
     * Existe separada por causa do seeder de volume: ele precisa dos mesmos
     * valores congelados para milhões de linhas que entram por insert em lote,
     * e um UPDATE por cobrança destruiria a carga. Com isto ele monta a linha
     * pela regra de produção, sem reescrever a fórmula.
     *
     * Devolve escalares, e não enum e Carbon, porque os dois consumidores
     * comem o mesmo array: o `update()` do Eloquent, que faz o cast na
     * entrada, e o insert cru do seeder, que não faz.
     *
     * @return array<string, string>
     */
    public function freeze(
        Billing $billing,
        CarbonInterface|string|null $paymentDate = null,
        ?string $paidAmount = null,
    ): array {
        $date = CarbonImmutable::parse(
            $paymentDate ?? CarbonImmutable::now(),
        )->startOfDay();

        $calculation = (new InterestCalculator($date))->for($billing);

        return [
            'status' => BillingStatus::Paid->value,
            'payment_date' => $date->toDateString(),
            'paid_interest_amount' => $calculation->interestAmount,
            // O valor efetivamente pago pode diferir do calculado (acordo,
            // desconto). Quando não informado, assume-se o valor atualizado.
            'paid_amount' => $paidAmount ?? $calculation->updatedAmount,
        ];
    }
}
