<?php

namespace App\Models;

use App\Domain\Customer\CustomerStatus;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'document', 'email', 'status'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
        ];
    }

    /**
     * @return HasMany<Billing, $this>
     */
    public function billings(): HasMany
    {
        return $this->hasMany(Billing::class);
    }
}
