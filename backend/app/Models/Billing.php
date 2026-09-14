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
     * O default de `status` existe na migration, mas o banco só o aplica no
     * INSERT: a instância recém-criada em memória ficaria com status nulo até
     * ser relida, e serializá-la quebraria. Declarar aqui mantém o model
     * consistente com o schema sem custo de round-trip.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => BillingStatus::Pending->value,
    ];

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
     * Vencida é condição derivada, não estado gravado: pendente com
     * vencimento no passado. Este é o lado PHP da regra; o relatório precisa
     * do mesmo em SQL para poder filtrar e ordenar no banco.
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
