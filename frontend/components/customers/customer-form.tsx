"use client";

import Link from "next/link";
import { useActionState } from "react";

import type { CustomerFormState } from "@/app/actions/customers";
import { Button, buttonClasses } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/field";
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

export function CustomerForm({
  action,
  customer,
  submitLabel,
  cancelHref,
}: CustomerFormProps) {
  const [state, formAction, isPending] = useActionState(action, INITIAL);

  return (
    <form action={formAction} className="flex max-w-xl flex-col gap-5" noValidate>
      {/* Mensagem geral: erro de rede ou o resumo do 422. Os erros de campo
          aparecem sob cada campo, vindos da mesma resposta. */}
      {state.message ? (
        <p
          role="alert"
          className="rounded-md border border-overdue/30 bg-overdue-soft px-3 py-2 text-sm text-overdue"
        >
          {state.message}
        </p>
      ) : null}

      <Field label="Nome" htmlFor="name" required errors={state.errors?.name}>
        <Input
          id="name"
          name="name"
          defaultValue={customer?.name}
          disabled={isPending}
          aria-invalid={state.errors?.name ? true : undefined}
        />
      </Field>

      <Field
        label="Documento"
        htmlFor="document"
        required
        hint="CPF ou CNPJ. Pode digitar com pontuação — ela é removida ao salvar."
        errors={state.errors?.document}
      >
        <Input
          id="document"
          name="document"
          inputMode="numeric"
          defaultValue={customer?.document}
          disabled={isPending}
          aria-invalid={state.errors?.document ? true : undefined}
          className="font-mono"
        />
      </Field>

      <Field label="E-mail" htmlFor="email" required errors={state.errors?.email}>
        <Input
          id="email"
          name="email"
          type="email"
          defaultValue={customer?.email}
          disabled={isPending}
          aria-invalid={state.errors?.email ? true : undefined}
        />
      </Field>

      <Field label="Status" htmlFor="status" required errors={state.errors?.status}>
        <Select
          id="status"
          name="status"
          defaultValue={customer?.status ?? "active"}
          disabled={isPending}
        >
          {CUSTOMER_STATUSES.map((status) => (
            <option key={status.value} value={status.value}>
              {status.label}
            </option>
          ))}
        </Select>
      </Field>

      <div className="flex items-center gap-3 border-t border-rule pt-5">
        <Button type="submit" disabled={isPending}>
          {isPending ? "Salvando…" : submitLabel}
        </Button>

        <Link
          href={cancelHref}
          className={buttonClasses({ variant: "ghost" })}
        >
          Cancelar
        </Link>
      </div>
    </form>
  );
}
