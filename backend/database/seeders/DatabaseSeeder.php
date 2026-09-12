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
                'role' => UserRole::Admin,
                'email_verified_at' => now(),
            ],
        );

        // O segundo usuário existe para o perfil de consulta poder ser visto
        // funcionando. Sem ele, a restrição só apareceria na suíte de testes —
        // e quem avalia o sistema teria que criar um usuário à mão para
        // conferir que ela existe.
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
