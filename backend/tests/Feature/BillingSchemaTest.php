<?php

namespace Tests\Feature;

use App\Domain\Billing\BillingStatus;
use App\Models\Billing;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Os states desta factory são a base de todo teste de juros e de relatório que
 * vem depois. Se `overdue()` mentir sobre o que é uma cobrança vencida, os
 * testes seguintes passam medindo a coisa errada.
 *
 * O tempo é congelado em todos eles: com o relógio andando, "vencida há 30
 * dias" viraria outra coisa amanhã.
 */
class BillingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_base_state_is_pending_and_within_term(): void
    {
        $this->travelTo('2026-06-15 12:00:00');

        $billing = Billing::factory()->create();

        $this->assertSame(BillingStatus::Pending, $billing->status);
        $this->assertTrue($billing->due_date->isFuture());
        $this->assertNull($billing->payment_date);
        $this->assertNull($billing->paid_amount);
    }

    public function test_overdue_is_due_in_the_past_and_still_pending(): void
    {
        $this->travelTo('2026-06-15 12:00:00');

        $billing = Billing::factory()->overdue(30)->create();

        $this->assertSame(BillingStatus::Pending, $billing->status);
        $this->assertSame('2026-05-16', $billing->due_date->toDateString());
        $this->assertNull($billing->payment_date);

        // Sem congelamento: cobrança não paga não tem valor gravado, ele é
        // calculado em tempo real.
        $this->assertNull($billing->paid_amount);
        $this->assertNull($billing->paid_interest_amount);
    }

    public function test_paid_freezes_without_interest_for_having_been_paid_within_term(): void
    {
        $this->travelTo('2026-06-15 12:00:00');

        $billing = Billing::factory()->paid()->create();

        $this->assertSame(BillingStatus::Paid, $billing->status);
        $this->assertTrue($billing->payment_date->lessThan($billing->due_date));
        $this->assertSame($billing->original_amount, $billing->paid_amount);
        $this->assertSame('0.00', $billing->paid_interest_amount);
    }

    public function test_paid_late_pays_after_the_due_date(): void
    {
        $this->travelTo('2026-06-15 12:00:00');

        $billing = Billing::factory()->paidLate(30)->create();

        $this->assertSame(BillingStatus::Paid, $billing->status);
        $this->assertSame('2026-05-11', $billing->due_date->toDateString());
        $this->assertSame('2026-06-10', $billing->payment_date->toDateString());
        // Carbon 3 devolve float em diffInDays; o cast mantém o assertSame estrito.
        $this->assertSame(30, (int) $billing->due_date->diffInDays($billing->payment_date));
    }

    public function test_a_billing_belongs_to_a_customer(): void
    {
        $customer = Customer::factory()->create(['name' => 'Acme Ltda']);
        $billing = Billing::factory()->for($customer)->create();

        $this->assertSame('Acme Ltda', $billing->customer->name);
        $this->assertTrue($customer->billings->contains($billing));
    }

    public function test_monetary_amounts_lose_no_cent(): void
    {
        $billing = Billing::factory()->create(['original_amount' => 1234.56]);

        // O cast decimal devolve string de propósito: float arredondaria, e o
        // relatório soma milhões destas linhas.
        $this->assertSame('1234.56', $billing->fresh()->original_amount);
    }

    public function test_the_customer_document_is_unique(): void
    {
        Customer::factory()->create(['document' => '12345678901']);

        $this->expectException(QueryException::class);

        Customer::factory()->create(['document' => '12345678901']);
    }
}
