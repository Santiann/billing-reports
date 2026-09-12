"use client";

import Link from "next/link";
import { useActionState } from "react";

import type { BillingFormState } from "@/app/actions/billings";
import { CustomerPicker } from "@/components/billings/customer-picker";
import { Button, buttonClasses } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/field";
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

export function BillingForm({
  action,
  billing,
  submitLabel,
  cancelHref,
}: BillingFormProps) {
  const [state, formAction, isPending] = useActionState(action, INITIAL);

  return (
    <form action={formAction} className="flex max-w-2xl flex-col gap-5" noValidate>
      {state.message ? (
        <p
          role="alert"
          className="rounded-md border border-overdue/30 bg-overdue-soft px-3 py-2 text-sm text-overdue"
        >
          {state.message}
        </p>
      ) : null}

      <Field
        label="Cliente"
        htmlFor="customer_id"
        required
        errors={state.errors?.customer_id}
      >
        <CustomerPicker
          name="customer_id"
          defaultCustomer={billing?.customer}
          disabled={isPending}
          hasError={Boolean(state.errors?.customer_id)}
        />
      </Field>

      <Field
        label="Descrição"
        htmlFor="description"
        required
        errors={state.errors?.description}
      >
        <Input
          id="description"
          name="description"
          defaultValue={billing?.description}
          disabled={isPending}
          aria-invalid={state.errors?.description ? true : undefined}
        />
      </Field>

      <div className="grid gap-5 sm:grid-cols-2">
        <Field
          label="Valor original (R$)"
          htmlFor="original_amount"
          required
          errors={state.errors?.original_amount}
        >
          <Input
            id="original_amount"
            name="original_amount"
            type="number"
            step="0.01"
            min="0.01"
            defaultValue={billing?.original_amount}
            disabled={isPending}
            aria-invalid={state.errors?.original_amount ? true : undefined}
            className="font-mono tabular-nums"
          />
        </Field>

        <Field
          label="Taxa de juros mensal"
          htmlFor="monthly_interest_rate"
          required
          hint="Em fração — 0,02 equivale a 2% ao mês."
          errors={state.errors?.monthly_interest_rate}
        >
          {/* Fração, não porcentagem: é como a coluna guarda. */}
          <Input
            id="monthly_interest_rate"
            name="monthly_interest_rate"
            type="number"
            step="0.0001"
            min="0"
            defaultValue={billing?.monthly_interest_rate ?? "0.0200"}
            disabled={isPending}
            aria-invalid={state.errors?.monthly_interest_rate ? true : undefined}
            className="font-mono tabular-nums"
          />
        </Field>

        <Field
          label="Data de emissão"
          htmlFor="issue_date"
          required
          errors={state.errors?.issue_date}
        >
          <Input
            id="issue_date"
            name="issue_date"
            type="date"
            defaultValue={billing?.issue_date}
            disabled={isPending}
            aria-invalid={state.errors?.issue_date ? true : undefined}
          />
        </Field>

        <Field
          label="Data de vencimento"
          htmlFor="due_date"
          required
          errors={state.errors?.due_date}
        >
          <Input
            id="due_date"
            name="due_date"
            type="date"
            defaultValue={billing?.due_date}
            disabled={isPending}
            aria-invalid={state.errors?.due_date ? true : undefined}
          />
        </Field>
      </div>

      {/* Status e pagamento não estão no formulário de propósito: quem faz a
          transição para paga é o registro de pagamento, que grava junto os
          valores congelados. */}

      <div className="flex items-center gap-3 border-t border-rule pt-5">
        <Button type="submit" disabled={isPending}>
          {isPending ? "Salvando…" : submitLabel}
        </Button>

        <Link href={cancelHref} className={buttonClasses({ variant: "ghost" })}>
          Cancelar
        </Link>
      </div>
    </form>
  );
}
