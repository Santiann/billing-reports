<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'document' => $this->document,
            'email' => $this->email,
            'status' => $this->status->value,
            // The label comes from the PHP enum so the translation is not duplicated in the
            // frontend and the two do not drift out of sync.
            'status_label' => $this->status->label(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
