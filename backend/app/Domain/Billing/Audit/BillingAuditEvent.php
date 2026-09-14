<?php

namespace App\Domain\Billing\Audit;

use App\Domain\Billing\BillingStatus;

enum BillingAuditEvent: string
{
    case Updated = 'updated';
    case Paid = 'paid';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Updated => 'Editada',
            self::Paid => 'Pagamento registrado',
            self::Reversed => 'Pagamento estornado',
        };
    }

    /**
     * O evento sai da TRANSIÇÃO de status, não de quem chamou.
     *
     * Nenhum ponto do código declara "isto é um pagamento": pendente que vira
     * paga é pagamento, paga que volta a pendente é estorno, venha de onde
     * vier. Assim nenhum caminho novo consegue rotular errado.
     */
    public static function fromTransition(?BillingStatus $antes, ?BillingStatus $depois): self
    {
        return match (true) {
            $antes === BillingStatus::Pending && $depois === BillingStatus::Paid => self::Paid,
            $antes === BillingStatus::Paid && $depois === BillingStatus::Pending => self::Reversed,
            default => self::Updated,
        };
    }
}
