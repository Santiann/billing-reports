<?php

namespace Database\Seeders;

use App\Domain\User\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // The brief does not call for public user registration, so access to the system starts
        // here. Credentials are documented in the README.
        //
        // firstOrCreate rather than factory()->create() so the seeder can run twice without
        // hitting the email's unique index.
        User::query()->firstOrCreate(
            ['email' => 'admin@billing.test'],
            [
                'name' => 'Administrador',
                'password' => 'password',
                'role' => UserRole::Admin,
                'email_verified_at' => now(),
            ],
        );

        // The second user exists so the read-only role can be seen working. Without it the
        // restriction would only show up in the test suite — and anyone looking at the system
        // would have to create a user by hand to confirm it exists.
        User::query()->firstOrCreate(
            ['email' => 'consulta@billing.test'],
            [
                'name' => 'Usuário de consulta',
                'password' => 'password',
                'role' => UserRole::Viewer,
                'email_verified_at' => now(),
            ],
        );
    }
}
