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
    private const CAMPOS = [
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
            'changes' => $this->mudancas(),
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
    private function mudancas(): array
    {
        $mudancas = [];
        $gravadas = $this->changes;

        foreach (self::CAMPOS as $campo => $rotulo) {
            if (array_key_exists($campo, $gravadas)) {
                $mudancas[] = $this->mudanca($campo, $rotulo, $gravadas[$campo]);
                unset($gravadas[$campo]);
            }
        }

        // Campo que ganhe coluna depois e ainda não tenha rótulo aparece com o
        // nome da coluna, em vez de sumir da trilha.
        foreach ($gravadas as $campo => $valores) {
            $mudancas[] = $this->mudanca($campo, $campo, $valores);
        }

        return $mudancas;
    }

    /**
     * @param  array{from: mixed, to: mixed}  $valores
     * @return array<string, mixed>
     */
    private function mudanca(string $campo, string $rotulo, array $valores): array
    {
        return [
            'field' => $campo,
            'label' => $rotulo,
            'from' => $valores['from'],
            'to' => $valores['to'],
        ];
    }
}
