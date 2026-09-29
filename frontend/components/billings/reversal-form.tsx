"use client";

import {
  useActionState,
  useRef,
  useState,
  useTransition,
  type FormEvent,
} from "react";

import { reversePayment, type ReversalFormState } from "@/app/actions/payments";
import { Button } from "@/components/ui/button";
import { newIdempotencyKey } from "@/lib/idempotency";

const INITIAL: ReversalFormState = {};

/**
 * A two-step reversal, with no modal.
 *
 * The first click only opens the confirmation, which says what is about to happen
 * to the money before it happens. A modal would be one more component for a
 * one-line question, and it would hide the paid amounts sitting just above — which
 * are exactly what someone needs to check before reversing.
 */
export function ReversalForm({ billingId }: { billingId: number }) {
  const action = reversePayment.bind(null, billingId);
  const [state, formAction, isActionPending] = useActionState(action, INITIAL);
  const [isTransitionPending, startTransition] = useTransition();
  const [confirming, setConfirming] = useState(false);

  const isPending = isActionPending || isTransitionPending;

  /*
   * One key per mount of the form.
   *
   * A reversal has no fields, so there is no content that changes between
   * attempts: every resend from this screen is the same operation. The key is born
   * on the click rather than during render, for the same reason as the payment
   * form — on the server and at hydration the draw would give two values.
   */
  const key = useRef<string | null>(null);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    key.current ??= newIdempotencyKey();

    const data = new FormData();
    data.set("idempotency_key", key.current);

    startTransition(() => formAction(data));
  }

  if (!confirming) {
    return (
      <div className="flex flex-col gap-3">
        <p className="text-sm text-ink-muted">
          Para um pagamento que não se sustentou — cheque devolvido,
          transferência revertida, baixa lançada na cobrança errada.
        </p>
        <div>
          <Button variant="secondary" onClick={() => setConfirming(true)}>
            Estornar pagamento
          </Button>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={submit} className="flex flex-col gap-4">
      {state.message ? (
        <p
          role="alert"
          className="rounded-md border border-overdue/30 bg-overdue-soft px-3 py-2 text-sm text-overdue"
        >
          {state.message}
        </p>
      ) : null}

      <p className="text-sm text-ink">
        A cobrança volta a <strong>pendente</strong> e os juros voltam a correr
        desde o vencimento original. Os valores pagos saem da ficha e ficam
        registrados no histórico.
      </p>

      <div className="flex flex-wrap gap-3">
        {/* `danger`: in this domain, the overdue colour already means loss. */}
        <Button type="submit" variant="danger" disabled={isPending}>
          {isPending ? "Estornando…" : "Confirmar estorno"}
        </Button>
        <Button
          type="button"
          variant="secondary"
          disabled={isPending}
          onClick={() => setConfirming(false)}
        >
          Cancelar
        </Button>
      </div>
    </form>
  );
}
