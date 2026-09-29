<?php

namespace App\Models;

use App\Domain\Billing\Audit\BillingAuditObserver;
use App\Domain\Billing\BillingDataVersionObserver;
use App\Domain\Billing\BillingStatus;
use Database\Factories\BillingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
#[ObservedBy([BillingAuditObserver::class, BillingDataVersionObserver::class])]
class Billing extends Model
{
    /** @use HasFactory<BillingFactory> */
    use HasFactory;

    /**
     * `status`'s default exists in the migration, but the database only applies it on the
     * INSERT: a freshly created in-memory instance would have a null status until it was read
     * back, and serialising it would break. Declaring it here keeps the model consistent with
     * the schema at no round-trip cost.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => BillingStatus::Pending->value,
    ];

    /**
     * The money casts are `decimal`, which returns a string. That is intentional: a float
     * would lose cents, and the report sums millions of rows.
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
     * Overdue is a derived condition, not a stored state: pending with the due date in the
     * past. This is the PHP side of the rule; the report needs the same in SQL to be able to
     * filter and sort in the database.
     */
    public function isOverdue(): bool
    {
        return $this->status === BillingStatus::Pending
            && $this->due_date->startOfDay()->isBefore(now()->startOfDay());
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<BillingAudit, $this>
     */
    public function audits(): HasMany
    {
        return $this->hasMany(BillingAudit::class);
    }
}
