"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useTransition, type FormEvent } from "react";

import { CustomerPicker } from "@/components/billings/customer-picker";
import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/field";
import type { Customer } from "@/types/customer";
import { DATE_FIELDS, REPORT_STATUSES } from "@/types/report";

type ReportFiltersProps = {
  /** Vem do servidor para o seletor reexibir o cliente já filtrado. */
  selectedCustomer?: Customer;
};

export function ReportFilters({ selectedCustomer }: ReportFiltersProps) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [isPending, startTransition] = useTransition();

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const data = new FormData(event.currentTarget);
    const params = new URLSearchParams();

    for (const key of [
      "date_field",
      "start_date",
      "end_date",
      "status",
      "customer_id",
    ]) {
      const value = String(data.get(key) ?? "");

      if (value) {
        params.set(key, value);
      }
    }

    // A ordenação é escolhida nos cabeçalhos da tabela, então é preservada.
    for (const key of ["sort", "direction", "per_page"]) {
      const value = searchParams.get(key);

      if (value) {
        params.set(key, value);
      }
    }

    startTransition(() => router.push(`/relatorio?${params.toString()}`));
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Field label="Período baseado em" htmlFor="date_field">
          {/* Qual das três datas define o período é escolha do usuário. */}
          <Select
            id="date_field"
            name="date_field"
            defaultValue={searchParams.get("date_field") ?? "due_date"}
          >
            {DATE_FIELDS.map((field) => (
              <option key={field.value} value={field.value}>
                {field.label}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Data inicial" htmlFor="start_date">
          <Input
            id="start_date"
            name="start_date"
            type="date"
            defaultValue={searchParams.get("start_date") ?? ""}
          />
        </Field>

        <Field label="Data final" htmlFor="end_date">
          <Input
            id="end_date"
            name="end_date"
            type="date"
            defaultValue={searchParams.get("end_date") ?? ""}
          />
        </Field>

        <Field label="Status" htmlFor="status">
          <Select
            id="status"
            name="status"
            defaultValue={searchParams.get("status") ?? ""}
          >
            <option value="">Todos</option>
            {REPORT_STATUSES.map((status) => (
              <option key={status.value} value={status.value}>
                {status.label}
              </option>
            ))}
          </Select>
        </Field>
      </div>

      <Field label="Cliente" htmlFor="customer_id">
        <CustomerPicker name="customer_id" defaultCustomer={selectedCustomer} />
      </Field>

      <div className="flex items-center gap-3 border-t border-rule pt-4">
        <Button type="submit" disabled={isPending}>
          {isPending ? "Gerando…" : "Gerar relatório"}
        </Button>

        {searchParams.toString() ? (
          <Button
            variant="ghost"
            onClick={() => startTransition(() => router.push("/relatorio"))}
          >
            Limpar
          </Button>
        ) : null}
      </div>
    </form>
  );
}
