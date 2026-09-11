<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // O teste dispensa cadastro público de usuários, então o acesso ao
        // sistema nasce daqui. Credenciais documentadas no README.
        //
        // firstOrCreate em vez de factory()->create() para o seeder poder
        // rodar duas vezes sem estourar a unique do e-mail.
        User::query()->firstOrCreate(
            ['email' => 'admin@billing.test'],
            [
                'name' => 'Administrador',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );
    }
}
