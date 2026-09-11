<?php

namespace Database\Factories;

use App\Domain\Billing\BillingStatus;
use App\Domain\Billing\RegisterPayment;
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

    /**
     * Paga antes de vencer: juros zero.
     *
     * O congelamento passa pelo RegisterPayment, o mesmo serviço que a API
     * usa. Escrever os valores à mão aqui faria a factory virar uma segunda
     * implementação da regra, e os testes passariam a validar a cópia em vez
     * do original.
     */
    public function paid(): static
    {
        return $this->paidOn(fn (CarbonImmutable $dueDate) => $dueDate->subDays(2))
            ->state(function () {
                $dueDate = CarbonImmutable::now()->startOfDay()->subDays(10);

                return [
                    'issue_date' => $dueDate->subDays(30),
                    'due_date' => $dueDate,
                ];
            });
    }

    /** Paga com atraso: os juros congelam na data do pagamento. */
    public function paidLate(int $daysLate = 30): static
    {
        return $this->paidOn(fn (CarbonImmutable $dueDate) => $dueDate->addDays($daysLate))
            ->state(function () use ($daysLate) {
                $dueDate = CarbonImmutable::now()->startOfDay()->subDays($daysLate + 5);

                return [
                    'issue_date' => $dueDate->subDays(30),
                    'due_date' => $dueDate,
                ];
            });
    }

    /**
     * Registra o pagamento depois da criação, pelo serviço de produção.
     *
     * Precisa ser `afterCreating`: o RegisterPayment opera sobre um model já
     * persistido, e o cálculo dos juros depende do vencimento que só existe
     * quando a linha foi gravada.
     *
     * @param  callable(CarbonImmutable): CarbonImmutable  $paymentDate
     */
    private function paidOn(callable $paymentDate): static
    {
        return $this->afterCreating(function (Billing $billing) use ($paymentDate): void {
            app(RegisterPayment::class)(
                $billing,
                $paymentDate(CarbonImmutable::parse($billing->due_date)),
            );
        });
    }
}
