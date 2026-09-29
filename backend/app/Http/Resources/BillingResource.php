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
        // In the listing the values already come from the SELECT (the calculator's SQL face);
        // on a single billing they are computed in PHP. InterestCalculatorTest asserts both
        // paths give the same number down to the cent.
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
            // whenLoaded: with the relation not loaded the key disappears, rather than firing
            // one query per row while serialising.
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
        ];
    }

    /** The SELECT returns a DOUBLE; the API delivers a two-digit decimal. */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
