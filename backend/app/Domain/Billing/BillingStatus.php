<?php

namespace App\Domain\Billing;

/**
 * Status armazenado de uma cobrança.
 *
 * "Vencida" não está aqui de propósito: é uma condição derivável
 * (`Pending` + vencimento no passado), não um estado gravado. Ver o comentário
 * na migration de billings.
 */
enum BillingStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Paid => 'Paga',
        };
    }
}
