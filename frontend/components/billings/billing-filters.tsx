"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useTransition } from "react";

import { BILLING_STATUSES } from "@/types/billing";

export function BillingFilters() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [isPending, startTransition] = useTransition();

  const currentSearch = searchParams.get("search") ?? "";

  function apply(changes: Record<string, string>) {
    const params = new URLSearchParams(searchParams.toString());

    for (const [key, value] of Object.entries(changes)) {
      if (value) {
        params.set(key, value);
      } else {
        params.delete(key);
      }
    }

    params.delete("page");
    params.delete("sucesso");

    startTransition(() => {
      router.push(`/cobrancas?${params.toString()}`);
    });
  }

  const fieldClass =
    "rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-slate-900 focus:ring-1 focus:ring-slate-900";

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        apply({ search: String(data.get("search") ?? "") });
      }}
      className="flex flex-wrap items-end gap-3"
    >
      <div className="flex min-w-56 flex-1 flex-col gap-1.5">
        <label htmlFor="search" className="text-sm font-medium text-slate-700">
          Buscar
        </label>
        <input
          id="search"
          name="search"
          type="search"
          key={currentSearch}
          defaultValue={currentSearch}
          placeholder="Descrição da cobrança"
          className={fieldClass}
        />
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="status" className="text-sm font-medium text-slate-700">
          Status
        </label>
        <select
          id="status"
          value={searchParams.get("status") ?? ""}
          onChange={(event) => apply({ status: event.target.value })}
          className={fieldClass}
        >
          <option value="">Todos</option>
          {BILLING_STATUSES.map((status) => (
            <option key={status.value} value={status.value}>
              {status.label}
            </option>
          ))}
        </select>
      </div>

      <button
        type="submit"
        disabled={isPending}
        className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400"
      >
        {isPending ? "Filtrando…" : "Filtrar"}
      </button>

      {searchParams.toString() ? (
        <button
          type="button"
          onClick={() => startTransition(() => router.push("/cobrancas"))}
          className="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100"
        >
          Limpar
        </button>
      ) : null}
    </form>
  );
}
