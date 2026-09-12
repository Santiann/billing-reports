<?php

namespace App\Models;

use App\Domain\User\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * O default do banco só vale no INSERT.
     *
     * Sem esta linha, a instância recém-criada em memória ficaria com o perfil
     * nulo até ser relida — e `$user->role->canWrite()` explodiria no meio de
     * uma autorização, que é o pior lugar possível para isso acontecer.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => UserRole::Viewer->value,
    ];
}
