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
     * The database's default only applies on the INSERT.
     *
     * Without this line, a freshly created in-memory instance would have a null role until it
     * was read back — and `$user->role->canWrite()` would blow up in the middle of an
     * authorisation, which is the worst possible place for that to happen.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => UserRole::Viewer->value,
    ];
}
