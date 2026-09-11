<?php

namespace App\Models;

use App\Domain\Billing\BillingStatus;
use Database\Factories\BillingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_id',
    'description',
    'original_amount',
    'monthly_interest_rate',
    'issue_date',
    'due_date',
    'payment_date',
    'status',
    'paid_amount',
    'paid_interest_amount',
])]
class Billing extends Model
{
    /** @use HasFactory<BillingFactory> */
    use HasFactory;

    /**
     * Os casts de dinheiro são `decimal`, que devolve string. É proposital:
     * float perderia centavo, e o relatório soma milhões de linhas.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:2',
            'monthly_interest_rate' => 'decimal:4',
            'issue_date' => 'date',
            'due_date' => 'date',
            'payment_date' => 'date',
            'status' => BillingStatus::class,
            'paid_amount' => 'decimal:2',
            'paid_interest_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
