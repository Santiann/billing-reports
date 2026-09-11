"use client";

import { useActionState } from "react";

import { registerPayment, type PaymentFormState } from "@/app/actions/payments";
import { formatCurrency } from "@/lib/format";
import type { Billing } from "@/types/billing";

const INITIAL: PaymentFormState = {};

function FieldError({ messages }: { messages?: string[] }) {
  if (!messages?.length) {
    return null;
  }

  return <p className="text-sm text-red-700">{messages[0]}</p>;
}

export function PaymentForm({ billing }: { billing: Billing }) {
  const action = registerPayment.bind(null, billing.id);
  const [state, formAction, isPending] = useActionState(action, INITIAL);

  const fieldClass =
    "rounded-md border border-slate-300 px-3 py-2 text-slate-900 outline-none focus:border-slate-900 focus:ring-1 focus:ring-slate-900 disabled:bg-slate-100";

  return (
    <form action={formAction} className="flex flex-col gap-4" noValidate>
      {state.message ? (
        <p
          role="alert"
          className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
        >
          {state.message}
        </p>
      ) : null}

      {/* O erro de "já está paga" vem na chave status, sem campo na tela. */}
      <FieldError messages={state.errors?.status} />

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-1.5">
          <label
            htmlFor="payment_date"
            className="text-sm font-medium text-slate-700"
          >
            Data do pagamento
          </label>
          <input
            id="payment_date"
            name="payment_date"
            type="date"
            disabled={isPending}
            className={fieldClass}
          />
          {/* Os juros congelam na data informada, não em hoje: pagamento
              retroativo produz o valor daquele dia. */}
          <p className="text-xs text-slate-500">
            Em branco usa hoje. Os juros congelam na data informada.
          </p>
          <FieldError messages={state.errors?.payment_date} />
        </div>

        <div className="flex flex-col gap-1.5">
          <label
            htmlFor="paid_amount"
            className="text-sm font-medium text-slate-700"
          >
            Valor recebido (R$)
          </label>
          <input
            id="paid_amount"
            name="paid_amount"
            type="number"
            step="0.01"
            min="0.01"
            placeholder={billing.updated_amount}
            disabled={isPending}
            className={fieldClass}
          />
          <p className="text-xs text-slate-500">
            Em branco usa o valor atualizado,{" "}
            {formatCurrency(billing.updated_amount)}.
          </p>
          <FieldError messages={state.errors?.paid_amount} />
        </div>
      </div>

      <div>
        <button
          type="submit"
          disabled={isPending}
          className="rounded-md bg-emerald-700 px-4 py-2 font-medium text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-slate-400"
        >
          {isPending ? "Registrando…" : "Registrar pagamento"}
        </button>
      </div>
    </form>
  );
}
