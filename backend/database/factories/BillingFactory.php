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
 * Every state builds dates relative to `now()`, never calendar literals. That is what makes
 * a test with `travelTo()` deterministic: with a fixed date the scenario would change meaning
 * as the clock moved on.
 */
class BillingFactory extends Factory
{
    protected $model = Billing::class;

    /**
     * The base state: pending and still within term. It accrues no interest.
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

    /** Overdue and unpaid: the only situation that accrues interest. */
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
     * Paid before falling due: zero interest.
     *
     * The freezing goes through RegisterPayment, the same service the API uses. Writing the
     * amounts by hand here would turn the factory into a second implementation of the rule, and
     * the tests would start validating the copy instead of the original.
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

    /** Paid late: the interest freezes at the payment date. */
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
     * Records the payment after creation, through the production service.
     *
     * It has to be `afterCreating`: RegisterPayment operates on an already persisted model, and
     * the interest calculation depends on the due date, which only exists once the row has been
     * written.
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
