"use client";

import { useActionState } from "react";

import { registerPayment, type PaymentFormState } from "@/app/actions/payments";
import { Button } from "@/components/ui/button";
import { Field, FieldError, Input } from "@/components/ui/field";
import { formatCurrency } from "@/lib/format";
import type { Billing } from "@/types/billing";

const INITIAL: PaymentFormState = {};

export function PaymentForm({ billing }: { billing: Billing }) {
  const action = registerPayment.bind(null, billing.id);
  const [state, formAction, isPending] = useActionState(action, INITIAL);

  return (
    <form action={formAction} className="flex flex-col gap-5" noValidate>
      {state.message ? (
        <p
          role="alert"
          className="rounded-md border border-overdue/30 bg-overdue-soft px-3 py-2 text-sm text-overdue"
        >
          {state.message}
        </p>
      ) : null}

      {/* O erro de "já está paga" vem na chave status, sem campo na tela. */}
      <FieldError messages={state.errors?.status} />

      <div className="grid gap-5 sm:grid-cols-2">
        <Field
          label="Data do pagamento"
          htmlFor="payment_date"
          hint="Em branco usa hoje. Os juros congelam na data informada."
          errors={state.errors?.payment_date}
        >
          {/* Os juros congelam na data informada, não em hoje: pagamento
              retroativo produz o valor daquele dia. */}
          <Input
            id="payment_date"
            name="payment_date"
            type="date"
            disabled={isPending}
            aria-invalid={state.errors?.payment_date ? true : undefined}
          />
        </Field>

        <Field
          label="Valor recebido (R$)"
          htmlFor="paid_amount"
          hint={`Em branco usa o valor atualizado, ${formatCurrency(billing.updated_amount)}.`}
          errors={state.errors?.paid_amount}
        >
          <Input
            id="paid_amount"
            name="paid_amount"
            type="number"
            step="0.01"
            min="0.01"
            placeholder={billing.updated_amount}
            disabled={isPending}
            aria-invalid={state.errors?.paid_amount ? true : undefined}
            className="font-mono tabular-nums"
          />
        </Field>
      </div>

      <div className="border-t border-rule pt-5">
        <Button type="submit" disabled={isPending}>
          {isPending ? "Registrando…" : "Registrar pagamento"}
        </Button>
      </div>
    </form>
  );
}
