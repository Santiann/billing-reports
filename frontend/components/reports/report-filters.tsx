"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useTransition, type FormEvent } from "react";

import { CustomerPicker } from "@/components/billings/customer-picker";
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

  const fieldClass =
    "rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-slate-900 focus:ring-1 focus:ring-slate-900";
  const labelClass = "text-sm font-medium text-slate-700";

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="flex flex-col gap-1.5">
          <label htmlFor="date_field" className={labelClass}>
            Período baseado em
          </label>
          {/* Qual das três datas define o período é escolha do usuário. */}
          <select
            id="date_field"
            name="date_field"
            defaultValue={searchParams.get("date_field") ?? "due_date"}
            className={fieldClass}
          >
            {DATE_FIELDS.map((field) => (
              <option key={field.value} value={field.value}>
                {field.label}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="start_date" className={labelClass}>
            Data inicial
          </label>
          <input
            id="start_date"
            name="start_date"
            type="date"
            defaultValue={searchParams.get("start_date") ?? ""}
            className={fieldClass}
          />
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="end_date" className={labelClass}>
            Data final
          </label>
          <input
            id="end_date"
            name="end_date"
            type="date"
            defaultValue={searchParams.get("end_date") ?? ""}
            className={fieldClass}
          />
        </div>

        <div className="flex flex-col gap-1.5">
          <label htmlFor="status" className={labelClass}>
            Status
          </label>
          <select
            id="status"
            name="status"
            defaultValue={searchParams.get("status") ?? ""}
            className={fieldClass}
          >
            <option value="">Todos</option>
            {REPORT_STATUSES.map((status) => (
              <option key={status.value} value={status.value}>
                {status.label}
              </option>
            ))}
          </select>
        </div>
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="customer_id" className={labelClass}>
          Cliente
        </label>
        <CustomerPicker name="customer_id" defaultCustomer={selectedCustomer} />
      </div>

      <div className="flex items-center gap-3">
        <button
          type="submit"
          disabled={isPending}
          className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400"
        >
          {isPending ? "Gerando…" : "Gerar relatório"}
        </button>

        {searchParams.toString() ? (
          <button
            type="button"
            onClick={() => startTransition(() => router.push("/relatorio"))}
            className="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100"
          >
            Limpar
          </button>
        ) : null}
      </div>
    </form>
  );
}
