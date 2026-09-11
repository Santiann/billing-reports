<?php

namespace App\Domain\Billing;

/**
 * Resultado do cálculo, com os valores já em string decimal de dois dígitos.
 *
 * String e não float: é o formato em que o valor atravessa a API e chega à
 * tela, e converter para float no caminho é onde o centavo se perde.
 */
final class InterestCalculation
{
    public function __construct(
        public readonly string $originalAmount,
        public readonly string $interestAmount,
        public readonly string $updatedAmount,
        public readonly int $daysLate,
    ) {}
}
