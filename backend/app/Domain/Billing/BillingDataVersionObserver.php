<?php

namespace App\Domain\Billing;

use App\Models\Billing;

/**
 * Sobe a versão dos dados a cada escrita de cobrança pelo Eloquent.
 *
 * `updated`, e não `saved`: o `saved` dispara mesmo quando nada mudou, e uma
 * edição que não altera nada invalidaria o cache à toa.
 *
 * O que não passa pelo Eloquent — a importação, que grava em lote, e o seeder
 * de volume — sobe a versão por conta própria.
 */
final class BillingDataVersionObserver
{
    public function __construct(private readonly BillingDataVersion $version) {}

    public function created(Billing $billing): void
    {
        $this->version->bump();
    }

    public function updated(Billing $billing): void
    {
        $this->version->bump();
    }

    public function deleted(Billing $billing): void
    {
        $this->version->bump();
    }
}
