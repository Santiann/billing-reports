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
     * Rótulo de cada campo, na ordem em que a tela os mostra.
     *
     * A ordem é da ficha da cobrança, não a de gravação: numa entrada de
     * pagamento o status vem primeiro porque é ele que conta o que aconteceu.
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
     * A ordem sai daqui, e não do que foi gravado.
     *
     * Coluna JSON do MySQL não guarda a ordem das chaves: ela reordena por
     * tamanho, e `{"from", "to"}` volta do banco como `{"to", "from"}`. Espalhar
     * o que veio do banco entregaria à API uma ordem que ninguém escolheu.
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

        // Campo que ganhe coluna depois e ainda não tenha rótulo aparece com o
        // nome da coluna, em vez de sumir da trilha.
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
