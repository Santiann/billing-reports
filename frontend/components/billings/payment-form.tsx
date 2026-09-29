"use client";

import { useActionState, useRef, useTransition, type FormEvent } from "react";

import { registerPayment, type PaymentFormState } from "@/app/actions/payments";
import { Button } from "@/components/ui/button";
import { Field, FieldError, Input } from "@/components/ui/field";
import { newIdempotencyKey } from "@/lib/idempotency";
import { formatCurrency } from "@/lib/format";
import type { Billing } from "@/types/billing";

const INITIAL: PaymentFormState = {};

export function PaymentForm({ billing }: { billing: Billing }) {
  const action = registerPayment.bind(null, billing.id);
  const [state, formAction, isActionPending] = useActionState(action, INITIAL);
  const [isTransitionPending, startTransition] = useTransition();

  const isPending = isActionPending || isTransitionPending;

  /*
   * The idempotency key for this attempt.
   *
   * It is drawn once and reused as long as the form's content does not change.
   * That rule is what separates the two cases:
   *
   *   same content     -> same key -> the backend returns the first result
   *                       instead of charging again. This is the double click,
   *                       and the resend after the connection drops.
   *
   *   content changed  -> new key  -> it is another operation. Someone who
   *                       corrected the date after an error is asking for
   *                       something else, and reusing the key would hand back
   *                       the old error.
   */
  const attempt = useRef<{ key: string; content: string } | null>(null);

  function attemptKey(data: FormData): string {
    const content = JSON.stringify([
      data.get("payment_date"),
      data.get("paid_amount"),
    ]);

    if (attempt.current?.content !== content) {
      attempt.current = { key: newIdempotencyKey(), content };
    }

    return attempt.current.key;
  }

  /*
   * Submitting goes through `onSubmit` because the key can only be born in the
   * browser: drawing it during render would give one value on the server and
   * another at hydration. Here it is drawn on the click, when only one side
   * exists. As a bonus React does not reset the form, so the typed values
   * survive a validation error.
   */
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const data = new FormData(event.currentTarget);
    data.set("idempotency_key", attemptKey(data));

    startTransition(() => formAction(data));
  }

  return (
    <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
      {state.message ? (
        <p
          role="alert"
          className="rounded-md border border-overdue/30 bg-overdue-soft px-3 py-2 text-sm text-overdue"
        >
          {state.message}
        </p>
      ) : null}

      {/* The "already paid" error arrives under the status key, with no field on screen. */}
      <FieldError messages={state.errors?.status} />

      <div className="grid gap-5 sm:grid-cols-2">
        <Field
          label="Data do pagamento"
          htmlFor="payment_date"
          hint="Em branco usa hoje. Os juros congelam na data informada."
          errors={state.errors?.payment_date}
        >
          {/* Interest freezes on the date given, not on today: a backdated
              payment produces that day's amount. */}
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
