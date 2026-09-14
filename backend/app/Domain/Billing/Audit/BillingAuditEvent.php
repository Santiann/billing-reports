<?php

namespace App\Domain\Billing\Audit;

use App\Domain\Billing\BillingStatus;

enum BillingAuditEvent: string
{
    case Updated = 'updated';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Updated => 'Editada',
            self::Paid => 'Pagamento registrado',
        };
    }

    /**
     * O evento sai da TRANSIÇÃO de status, não de quem chamou.
     *
     * Nenhum ponto do código declara "isto é um pagamento": pendente que vira
     * paga é pagamento, venha de onde vier. Assim nenhum caminho novo consegue
     * rotular errado, e o estorno do próximo commit é classificado pela mesma
     * regra.
     */
    public static function fromTransition(?BillingStatus $antes, ?BillingStatus $depois): self
    {
        return $antes === BillingStatus::Pending && $depois === BillingStatus::Paid
            ? self::Paid
            : self::Updated;
    }
}
