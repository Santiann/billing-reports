<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            // O rótulo vem do enum de PHP para não duplicar a tradução no
            // frontend e as duas saírem de sincronia.
            'role_label' => $this->role->label(),
            // A tela usa isto para esconder o que o perfil não pode fazer. É
            // conveniência, não barreira: quem manda no acesso é o backend.
            'can_write' => $this->role->canWrite(),
        ];
    }
}
