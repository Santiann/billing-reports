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
    /** Só inserção: não há o que atualizar, então não há quando. */
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
     * Uma trilha que se edita não prova nada.
     *
     * A recusa mora no model para valer em todo caminho que passe pelo
     * Eloquent — tinker incluído. Consulta crua ainda passaria; fechar isso de
     * vez pediria permissão no banco, o que o README discute.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException(
            'A trilha de auditoria não se altera: registro errado se corrige com outro registro.',
        ));

        static::deleting(fn () => throw new LogicException(
            'A trilha de auditoria não se apaga.',
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
