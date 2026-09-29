<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Status and payment data are deliberately absent from the rules.
 *
 * Only what passes through `rules()` reaches `validated()`, so sending them has no effect.
 * Accepting `status = paid` here would create a paid billing without the frozen amounts —
 * what makes that transition is recording a payment.
 */
class StoreBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'description' => ['required', 'string', 'max:255'],
            'original_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'monthly_interest_rate' => ['required', 'numeric', 'min:0', 'max:9.9999'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
        ];
    }
}
