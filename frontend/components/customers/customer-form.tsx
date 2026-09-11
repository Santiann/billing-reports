"use client";

import Link from "next/link";
import { useActionState } from "react";

import type { CustomerFormState } from "@/app/actions/customers";
import { CUSTOMER_STATUSES, type Customer } from "@/types/customer";

type CustomerFormProps = {
  action: (
    state: CustomerFormState,
    formData: FormData,
  ) => Promise<CustomerFormState>;
  customer?: Customer;
  submitLabel: string;
  cancelHref: string;
};

const INITIAL: CustomerFormState = {};

function FieldError({ messages }: { messages?: string[] }) {
  if (!messages?.length) {
    return null;
  }

  return <p className="text-sm text-red-700">{messages[0]}</p>;
}

export function CustomerForm({
  action,
  customer,
  submitLabel,
  cancelHref,
}: CustomerFormProps) {
  const [state, formAction, isPending] = useActionState(action, INITIAL);

  const fieldClass =
    "rounded-md border border-slate-300 px-3 py-2 text-slate-900 outline-none focus:border-slate-900 focus:ring-1 focus:ring-slate-900 disabled:bg-slate-100";

  return (
    <form action={formAction} className="flex max-w-xl flex-col gap-4" noValidate>
      {/* Mensagem geral: erro de rede ou o resumo do 422. */}
      {state.message ? (
        <p
          role="alert"
          className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
        >
          {state.message}
        </p>
      ) : null}

      <div className="flex flex-col gap-1.5">
        <label htmlFor="name" className="text-sm font-medium text-slate-700">
          Nome
        </label>
        <input
          id="name"
          name="name"
          defaultValue={customer?.name}
          disabled={isPending}
          className={fieldClass}
        />
        <FieldError messages={state.errors?.name} />
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="document" className="text-sm font-medium text-slate-700">
          Documento
        </label>
        <input
          id="document"
          name="document"
          defaultValue={customer?.document}
          placeholder="CPF ou CNPJ"
          disabled={isPending}
          className={fieldClass}
        />
        {/* A máscara é removida no backend: o valor é gravado só com dígitos. */}
        <p className="text-xs text-slate-500">
          Pode digitar com pontuação — ela é removida ao salvar.
        </p>
        <FieldError messages={state.errors?.document} />
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="email" className="text-sm font-medium text-slate-700">
          E-mail
        </label>
        <input
          id="email"
          name="email"
          type="email"
          defaultValue={customer?.email}
          disabled={isPending}
          className={fieldClass}
        />
        <FieldError messages={state.errors?.email} />
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="status" className="text-sm font-medium text-slate-700">
          Status
        </label>
        <select
          id="status"
          name="status"
          defaultValue={customer?.status ?? "active"}
          disabled={isPending}
          className={fieldClass}
        >
          {CUSTOMER_STATUSES.map((status) => (
            <option key={status.value} value={status.value}>
              {status.label}
            </option>
          ))}
        </select>
        <FieldError messages={state.errors?.status} />
      </div>

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
