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
             * A factory cria ADMINISTRADOR, ao contrário do default do banco.
             *
             * Não é contradição: o default protege quem esquece de escolher em
             * produção, e a factory serve a testes que quase sempre precisam
             * escrever. Um default de consulta aqui faria dezenas de testes
             * que nada têm a ver com perfil falharem com 403.
             */
            'role' => UserRole::Admin,
        ];
    }

    /** Perfil de consulta: lê tudo, não escreve nada. */
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
