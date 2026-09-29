<?php

namespace App\Models;

use App\Domain\Billing\Audit\BillingAuditEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['billing_id', 'user_id', 'event', 'changes'])]
class BillingAudit extends Model
{
    /** Insert only: there is nothing to update, so there is no when. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'event' => BillingAuditEvent::class,
            'changes' => 'array',
        ];
    }

    /**
     * A trail that can be edited proves nothing.
     *
     * The refusal lives in the model so it holds on every path that goes through Eloquent —
     * tinker included. A raw query would still get past; closing that for good would take
     * database permissions, which the README discusses.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException(
            'The audit trail cannot be altered: a wrong record is corrected with another record.',
        ));

        static::deleting(fn () => throw new LogicException(
            'The audit trail cannot be deleted.',
        ));
    }

    /** @return BelongsTo<Billing, $this> */
    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
