"use client";

import Link from "next/link";
import { useActionState } from "react";

import type { BillingFormState } from "@/app/actions/billings";
import { CustomerPicker } from "@/components/billings/customer-picker";
import type { Billing } from "@/types/billing";

type BillingFormProps = {
  action: (
    state: BillingFormState,
    formData: FormData,
  ) => Promise<BillingFormState>;
  billing?: Billing;
  submitLabel: string;
  cancelHref: string;
};

const INITIAL: BillingFormState = {};

function FieldError({ messages }: { messages?: string[] }) {
  if (!messages?.length) {
    return null;
  }

  return <p className="text-sm text-red-700">{messages[0]}</p>;
}

export function BillingForm({
  action,
  billing,
  submitLabel,
  cancelHref,
}: BillingFormProps) {
  const [state, formAction, isPending] = useActionState(action, INITIAL);

  const fieldClass =
    "rounded-md border border-slate-300 px-3 py-2 text-slate-900 outline-none focus:border-slate-900 focus:ring-1 focus:ring-slate-900 disabled:bg-slate-100";
  const labelClass = "text-sm font-medium text-slate-700";

  return (
    <form action={formAction} className="flex max-w-2xl flex-col gap-4" noValidate>
      {state.message ? (
        <p
          role="alert"
          className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
        >
          {state.message}
        </p>
      ) : null}

      <div className="flex flex-col gap-1.5">
        <label htmlFor="customer_id" className={labelClass}>
          Cliente
        </label>
        <CustomerPicker
          name="customer_id"
          defaultCustomer={billing?.customer}
          disabled={isPending}
          hasError={Boolean(state.errors?.customer_id)}
        />
        <FieldError messages={state.errors?.customer_id} />
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="description" className={labelClass}>
          Descrição
        </label>
        <input
          id="description"
          name="description"
          defaultValue={billing?.description}
          disabled={isPending}
          className={fieldClass}
        />
        <FieldError messages={state.errors?.description} />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-1.5">
          <label htmlFor="original_amount" className={labelClass}>
            Valor original (R$)
          </label>
          <input
            id="original_amount"
            name="original_amount"
            type="number"
            step="0.01"
            min="0.01"
            defaultValue={billing?.original_amount}
            disabled={isPending}
            className={fieldClass}
          />
          <FieldError messages={state.errors?.original_amount} />
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="monthly_interest_rate" className={labelClass}>
            Taxa de juros mensal
          </label>
          <input
            id="monthly_interest_rate"
            name="monthly_interest_rate"
            type="number"
            step="0.0001"
            min="0"
            defaultValue={billing?.monthly_interest_rate ?? "0.0200"}
            disabled={isPending}
            className={fieldClass}
          />
          {/* Fração, não porcentagem: é como a coluna guarda. */}
          <p className="text-xs text-slate-500">
            Em fração — 0,02 equivale a 2% ao mês.
          </p>
          <FieldError messages={state.errors?.monthly_interest_rate} />
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="issue_date" className={labelClass}>
            Data de emissão
          </label>
          <input
            id="issue_date"
            name="issue_date"
            type="date"
            defaultValue={billing?.issue_date}
            disabled={isPending}
            className={fieldClass}
          />
          <FieldError messages={state.errors?.issue_date} />
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="due_date" className={labelClass}>
            Data de vencimento
          </label>
          <input
            id="due_date"
            name="due_date"
            type="date"
            defaultValue={billing?.due_date}
            disabled={isPending}
            className={fieldClass}
          />
          <FieldError messages={state.errors?.due_date} />
        </div>
      </div>

      {/* Status e pagamento não estão no formulário de propósito: quem faz a
          transição para paga é o registro de pagamento, que grava junto os
          valores congelados. */}

      <div className="flex items-center gap-3">
        <button
          type="submit"
          disabled={isPending}
          className="rounded-md bg-slate-900 px-4 py-2 font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400"
        >
          {isPending ? "Salvando…" : submitLabel}
        </button>

        <Link
          href={cancelHref}
          className="text-sm font-medium text-slate-600 transition hover:text-slate-900"
        >
          Cancelar
        </Link>
      </div>
    </form>
  );
}
