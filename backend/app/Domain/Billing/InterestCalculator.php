<?php

namespace App\Domain\Billing;

use App\Models\Billing;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Fonte única da regra de juros.
 *
 * Juros compostos: valor_original * (1 + taxa_mensal) ^ (dias_atraso / 30).
 *
 * A classe tem duas faces e elas precisam concordar até o centavo:
 *
 *   sqlExpression (updatedAmountSql / interestAmountSql)
 *     Usada em selectRaw na listagem e nas agregações. É o que permite
 *     ORDENAR por valor atualizado e SOMAR juros sobre o conjunto filtrado
 *     inteiro sem carregar nada em memória.
 *
 *   for(Billing)
 *     Usada para exibir uma cobrança isolada.
 *
 * InterestCalculatorTest roda a mesma matriz de casos pelas duas e afirma
 * igualdade. Sem esse teste as duas divergem em silêncio.
 */
final class InterestCalculator
{
    private const DAYS_IN_MONTH = 30;

    /**
     * A data de referência desce do PHP em vez de a face SQL usar CURDATE().
     *
     * Isso não é preciosismo: `travelTo()` move o relógio do PHP e não o do
     * MySQL. Com CURDATE() embutido, o teste de consistência compararia PHP em
     * tempo congelado contra SQL em tempo real e nunca fecharia.
     *
     * Também é o que permite calcular juros "na data do pagamento", que é
     * exatamente o que o congelamento precisa.
     */
    public function __construct(
        private readonly CarbonInterface|string|null $reference = null,
    ) {}

    private function referenceDate(): CarbonImmutable
    {
        return $this->reference === null
            ? CarbonImmutable::now()->startOfDay()
            : CarbonImmutable::parse($this->reference)->startOfDay();
    }

    // --- face PHP -----------------------------------------------------

    public function for(Billing $billing): InterestCalculation
    {
        // Cobrança paga não acumula juros: os valores vêm das colunas
        // gravadas no ato do pagamento, nunca de recálculo. Recalcular faria
        // uma cobrança paga com atraso mudar de valor a cada dia que passa.
        if ($billing->status === BillingStatus::Paid) {
            return new InterestCalculation(
                originalAmount: $this->money($billing->original_amount),
                interestAmount: $this->money($billing->paid_interest_amount ?? '0'),
                updatedAmount: $this->money(
                    $billing->paid_amount ?? $billing->original_amount,
                ),
                daysLate: $billing->payment_date === null
                    ? 0
                    : $this->daysBetween($billing->due_date, $billing->payment_date),
            );
        }

        $original = (float) $billing->original_amount;
        $daysLate = $this->daysBetween($billing->due_date, $this->referenceDate());

        $updated = $daysLate <= 0
            ? round($original, 2)
            : round(
                $original * pow(
                    1 + (float) $billing->monthly_interest_rate,
                    $daysLate / self::DAYS_IN_MONTH,
                ),
                2,
            );

        return new InterestCalculation(
            originalAmount: $this->money($original),
            // Subtrai do valor JÁ arredondado, na mesma ordem que a face SQL:
            // arredondar a diferença separadamente divergiria em um centavo.
            interestAmount: $this->money($updated - round($original, 2)),
            updatedAmount: $this->money($updated),
            daysLate: $daysLate,
        );
    }

    // --- face SQL -----------------------------------------------------

    /**
     * A data entra como literal, e isso é seguro: ela é gerada aqui a partir
     * de um Carbon, nunca vem da requisição.
     */
    public function daysLateSql(string $table = 'billings'): string
    {
        $reference = $this->referenceDate()->toDateString();

        return "GREATEST(DATEDIFF('{$reference}', {$table}.due_date), 0)";
    }

    /**
     * "Vencida" em SQL: pendente com vencimento no passado.
     *
     * Mora aqui, junto do cálculo, porque é a mesma regra vista de outro
     * ângulo — e pela mesma razão usa a data vinda do PHP, não CURDATE().
     */
    public function overdueSql(string $table = 'billings'): string
    {
        $reference = $this->referenceDate()->toDateString();

        return "({$table}.status = '".BillingStatus::Pending->value."'"
            ." AND {$table}.due_date < '{$reference}')";
    }

    public function updatedAmountSql(string $table = 'billings'): string
    {
        return "CASE WHEN {$table}.status = '".BillingStatus::Paid->value."'"
            ." THEN COALESCE({$table}.paid_amount, {$table}.original_amount)"
            ." ELSE {$this->compoundSql($table)}"
            .' END';
    }

    public function interestAmountSql(string $table = 'billings'): string
    {
        return "CASE WHEN {$table}.status = '".BillingStatus::Paid->value."'"
            ." THEN COALESCE({$table}.paid_interest_amount, 0)"
            ." ELSE {$this->compoundSql($table)} - {$table}.original_amount"
            .' END';
    }

    /**
     * `/ 30e0` não é estilo, é correção.
     *
     * Em MySQL a divisão de DECIMAL devolve DECIMAL truncado em quatro casas
     * por padrão: 400 / 30 vira 13.3333, enquanto em PHP é 13.333333…. Com
     * expoentes diferentes, POW devolve valores diferentes e as duas faces
     * divergem. O literal `30e0` é double e força a divisão a virar double.
     */
    private function compoundSql(string $table): string
    {
        return "ROUND({$table}.original_amount * POW("
            ."1 + {$table}.monthly_interest_rate, "
            .'('.$this->daysLateSql($table).') / '.self::DAYS_IN_MONTH.'e0'
            .'), 2)';
    }

    // --- helpers ------------------------------------------------------

    private function daysBetween(CarbonInterface $due, CarbonInterface|string $target): int
    {
        // CarbonImmutable para startOfDay() não mutar a data do model.
        $from = CarbonImmutable::parse($due)->startOfDay();
        $to = CarbonImmutable::parse($target)->startOfDay();

        return max(0, (int) $from->diffInDays($to));
    }

    private function money(string|float $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
