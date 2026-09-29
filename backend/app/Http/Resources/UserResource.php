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
            // The label comes from the PHP enum so the translation is not duplicated in the
            // frontend and the two do not drift out of sync.
            'role_label' => $this->role->label(),
            // The screen uses this to hide what the role cannot do. It is convenience, not a
            // barrier: the backend is what governs access.
            'can_write' => $this->role->canWrite(),
        ];
    }
}
