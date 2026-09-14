<?php

namespace App\Domain\Billing;

use App\Models\Billing;

/**
 * Estorna o pagamento: a cobrança volta a pendente.
 *
 * Limpar as colunas de pagamento é tudo o que o estorno precisa fazer, e é por
 * isso que ele é pequeno. Os juros voltam a correr desde o vencimento original
 * sem nenhuma regra nova: o InterestCalculator só lê as colunas congeladas
 * quando a cobrança está paga, e uma cobrança pendente é calculada a partir do
 * vencimento — nas duas faces, PHP e SQL.
 *
 * Os valores que saem daqui não se perdem: a trilha de auditoria os grava no
 * `from` da entrada de estorno, dentro da mesma transação.
 */
final class ReversePayment
{
    public function __invoke(Billing $billing): Billing
    {
        $billing->updateOrFail([
            'status' => BillingStatus::Pending->value,
            'payment_date' => null,
            'paid_amount' => null,
            'paid_interest_amount' => null,
        ]);

        return $billing;
    }
}
