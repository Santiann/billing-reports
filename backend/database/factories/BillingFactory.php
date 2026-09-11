<?php

namespace Database\Factories;

use App\Domain\Billing\BillingStatus;
use App\Models\Billing;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Billing>
 *
 * Todos os states montam datas relativas a `now()`, nunca literais de
 * calendário. É isso que faz um teste com `travelTo()` ser determinístico: com
 * data fixa o cenário mudaria de significado conforme o relógio andasse.
 */
class BillingFactory extends Factory
{
    protected $model = Billing::class;

    /**
     * Estado base: pendente e ainda dentro do prazo. Não acumula juros.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $dueDate = CarbonImmutable::now()->startOfDay()->addDays(20);

        return [
            'customer_id' => Customer::factory(),
            'description' => fake()->sentence(4),
            'original_amount' => fake()->randomFloat(2, 100, 10_000),
            'monthly_interest_rate' => fake()->randomElement(['0.0100', '0.0200', '0.0350']),
            'issue_date' => $dueDate->subDays(30),
            'due_date' => $dueDate,
            'payment_date' => null,
            'status' => BillingStatus::Pending,
            'paid_amount' => null,
            'paid_interest_amount' => null,
        ];
    }

    /** Vencida e não paga: é a única situação que acumula juros. */
    public function overdue(int $daysLate = 30): static
    {
        return $this->state(function (array $attributes) use ($daysLate) {
            $dueDate = CarbonImmutable::now()->startOfDay()->subDays($daysLate);

            return [
                'issue_date' => $dueDate->subDays(30),
                'due_date' => $dueDate,
                'payment_date' => null,
                'status' => BillingStatus::Pending,
                'paid_amount' => null,
                'paid_interest_amount' => null,
            ];
        });
    }

    /** Paga antes de vencer: juros zero, e o congelamento reflete isso. */
    public function paid(): static
    {
        return $this->state(function (array $attributes) {
            $dueDate = CarbonImmutable::now()->startOfDay()->subDays(10);

            return [
                'issue_date' => $dueDate->subDays(30),
                'due_date' => $dueDate,
                'payment_date' => $dueDate->subDays(2),
                'status' => BillingStatus::Paid,
                'paid_amount' => $attributes['original_amount'],
                'paid_interest_amount' => '0.00',
            ];
        });
    }

    /**
     * Paga com atraso: os juros congelaram na data do pagamento.
     *
     * `paid_amount` e `paid_interest_amount` ficam nulos aqui de propósito.
     * Preenchê-los exigiria repetir a fórmula de juros dentro da factory, e a
     * regra tem uma fonte só — o InterestCalculator, que nasce na etapa
     * `feat: add overdue interest calculation`. É lá que este state passa a
     * gravar os valores congelados.
     */
    public function paidLate(int $daysLate = 30): static
    {
        return $this->state(function (array $attributes) use ($daysLate) {
            $dueDate = CarbonImmutable::now()->startOfDay()->subDays($daysLate + 5);

            return [
                'issue_date' => $dueDate->subDays(30),
                'due_date' => $dueDate,
                'payment_date' => $dueDate->addDays($daysLate),
                'status' => BillingStatus::Paid,
            ];
        });
    }
}
