<?php

use App\Domain\User\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil de acesso do usuário.
 *
 * O default da coluna é `viewer`, o menor privilégio: usuário criado por um
 * caminho que esqueceu de definir o perfil não sai escrevendo. Esse é o
 * comportamento seguro quando alguém erra.
 *
 * Os usuários que JÁ EXISTEM viram administradores, e isso não contradiz o
 * default: antes desta migration não havia outro perfil, então quem estava lá
 * dentro era administrador por definição. Aplicar o default a eles tiraria o
 * acesso de escrita de quem já operava o sistema.
 *
 * String curta e não ENUM do MySQL, pelo mesmo motivo do status do cliente:
 * acrescentar um perfil vira mudança de código, não ALTER TABLE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)
                ->default(UserRole::Viewer->value)
                ->after('password');
        });

        DB::table('users')->update(['role' => UserRole::Admin->value]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
