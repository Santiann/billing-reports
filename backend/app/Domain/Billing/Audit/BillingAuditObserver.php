<?php

namespace App\Domain\Billing\Audit;

use App\Models\Billing;
use App\Models\BillingAudit;
use BackedEnum;
use Carbon\CarbonInterface;

/**
 * Grava a trilha a cada alteração de cobrança.
 *
 * Observer, e não uma chamada explícita em cada ponto que altera: a trilha
 * precisa ser completa, e chamada explícita é o tipo de coisa que o próximo
 * caminho de escrita esquece. Aqui toda alteração pelo Eloquent entra, venha
 * do controller, do registro de pagamento ou do tinker.
 *
 * O preço é que consulta crua passa por fora sem aviso. Por isso existe o
 * teste que varre `app/` atrás de `update` direto em `billings`.
 */
final class BillingAuditObserver
{
    /** Carimbos do próprio Eloquent: mudam em toda gravação e não dizem nada. */
    private const IGNORADOS = ['created_at', 'updated_at'];

    /**
     * `updated`, e não `updating`: aqui o id existe e a gravação já passou pelo
     * banco, e o original ainda não foi sincronizado — `getOriginal()` ainda
     * devolve o valor de antes.
     *
     * A escrita da trilha acontece dentro da transação de quem alterou
     * (`updateOrFail`). Se ela falhar, a alteração volta junto.
     */
    public function updated(Billing $billing): void
    {
        $mudancas = [];

        foreach (array_keys($billing->getChanges()) as $campo) {
            if (in_array($campo, self::IGNORADOS, true)) {
                continue;
            }

            $mudancas[$campo] = [
                'from' => $this->escalar($billing->getOriginal($campo)),
                'to' => $this->escalar($billing->getAttribute($campo)),
            ];
        }

        if ($mudancas === []) {
            return;
        }

        BillingAudit::create([
            'billing_id' => $billing->id,
            // Nulo fora de requisição: console, comando artisan.
            'user_id' => auth()->id(),
            'event' => BillingAuditEvent::fromTransition(
                $billing->getOriginal('status'),
                $billing->status,
            ),
            'changes' => $mudancas,
        ]);
    }

    /**
     * O valor como o banco guarda, para a trilha não depender dos casts.
     *
     * Os valores passam pelos casts do model antes de chegar aqui, e é isso que
     * faz "1000" e "1000.00" serem o mesmo número: o Eloquent só marca como
     * alterado o que o cast considera diferente.
     */
    private function escalar(mixed $valor): string|int|float|null
    {
        return match (true) {
            $valor instanceof BackedEnum => $valor->value,
            $valor instanceof CarbonInterface => $valor->toDateString(),
            default => $valor,
        };
    }
}
