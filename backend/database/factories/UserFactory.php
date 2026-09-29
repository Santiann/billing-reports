<?php

namespace Database\Factories;

use App\Domain\User\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            /*
             * The factory creates an ADMINISTRATOR, unlike the database's default.
             *
             * That is not a contradiction: the default protects whoever forgets to choose in
             * production, and the factory serves tests that almost always need to write. A
             * read-only default here would make dozens of tests that have nothing to do with
             * roles fail with a 403.
             */
            'role' => UserRole::Admin,
        ];
    }

    /** The read-only role: reads everything, writes nothing. */
    public function viewer(): static
    {
        return $this->state(fn () => ['role' => UserRole::Viewer]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
