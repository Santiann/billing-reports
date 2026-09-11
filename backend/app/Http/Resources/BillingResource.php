<?php

namespace App\Http\Resources;

use App\Domain\Billing\InterestCalculator;
use App\Models\Billing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Billing
 */
class BillingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Na listagem os valores já vêm do SELECT (face SQL do calculador);
        // numa cobrança isolada são calculados em PHP. InterestCalculatorTest
        // afirma que os dois caminhos dão o mesmo número até o centavo.
        $fromQuery = $this->resource->getAttribute('updated_amount') !== null;

        $calculation = $fromQuery
            ? null
            : (new InterestCalculator())->for($this->resource);

        return [
            'id' => $this->id,
            'description' => $this->description,
            'original_amount' => $this->original_amount,
            'monthly_interest_rate' => $this->monthly_interest_rate,
            'issue_date' => $this->issue_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'payment_date' => $this->payment_date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_overdue' => $this->isOverdue(),
            'paid_amount' => $this->paid_amount,
            'paid_interest_amount' => $this->paid_interest_amount,
            'interest_amount' => $fromQuery
                ? $this->money($this->resource->getAttribute('interest_amount'))
                : $calculation->interestAmount,
            'updated_amount' => $fromQuery
                ? $this->money($this->resource->getAttribute('updated_amount'))
                : $calculation->updatedAmount,
            // whenLoaded: sem relação carregada a chave some, em vez de
            // disparar uma consulta por linha na serialização.
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
        ];
    }

    /** O SELECT devolve DOUBLE; a API entrega decimal de dois dígitos. */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
