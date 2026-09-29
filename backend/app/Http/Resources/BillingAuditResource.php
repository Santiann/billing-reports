<?php

namespace App\Http\Resources;

use App\Models\BillingAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BillingAudit
 */
class BillingAuditResource extends JsonResource
{
    /**
     * Each field's label, in the order the screen shows them.
     *
     * The order is the billing detail panel's, not the write order: in a payment entry the
     * status comes first because it is what tells the story of what happened.
     */
    private const FIELDS = [
        'status' => 'Status',
        'customer_id' => 'Cliente',
        'description' => 'Descrição',
        'original_amount' => 'Valor original',
        'monthly_interest_rate' => 'Taxa de juros',
        'issue_date' => 'Emissão',
        'due_date' => 'Vencimento',
        'payment_date' => 'Data do pagamento',
        'paid_amount' => 'Valor pago',
        'paid_interest_amount' => 'Juros no pagamento',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event->value,
            'event_label' => $this->event->label(),
            'user' => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
            'changes' => $this->changes(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * The order comes from here, and not from what was stored.
     *
     * A MySQL JSON column does not keep the order of its keys: it reorders by size, and
     * `{"from", "to"}` comes back from the database as `{"to", "from"}`. Spreading what came
     * from the database would hand the API an order nobody chose.
     *
     * @return array<int, array<string, mixed>>
     */
    private function changes(): array
    {
        $changes = [];
        $stored = $this->changes;

        foreach (self::FIELDS as $field => $label) {
            if (array_key_exists($field, $stored)) {
                $changes[] = $this->change($field, $label, $stored[$field]);
                unset($stored[$field]);
            }
        }

        // A field that gains a column later and has no label yet shows up under the column's
        // name, rather than disappearing from the trail.
        foreach ($stored as $field => $values) {
            $changes[] = $this->change($field, $field, $values);
        }

        return $changes;
    }

    /**
     * @param  array{from: mixed, to: mixed}  $values
     * @return array<string, mixed>
     */
    private function change(string $field, string $label, array $values): array
    {
        return [
            'field' => $field,
            'label' => $label,
            'from' => $values['from'],
            'to' => $values['to'],
        ];
    }
}
