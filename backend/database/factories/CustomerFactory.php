<?php

namespace Database\Factories;

use App\Domain\Customer\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            // Digits only: searching by document must not depend on formatting.
            'document' => fake()->unique()->numerify('###########'),
            'email' => fake()->unique()->companyEmail(),
            'status' => CustomerStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CustomerStatus::Inactive,
        ]);
    }
}
