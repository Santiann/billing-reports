"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useTransition } from "react";

import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/field";
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

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        apply({ search: String(data.get("search") ?? "") });
      }}
      className="flex flex-wrap items-end gap-3"
    >
      <div className="min-w-56 flex-1">
        <Field label="Buscar" htmlFor="search">
          {/* Não-controlado, com a URL como fonte da verdade. A `key` remonta
              o campo quando o parâmetro muda por fora. */}
          <Input
            id="search"
            name="search"
            type="search"
            key={currentSearch}
            defaultValue={currentSearch}
            placeholder="Descrição da cobrança"
          />
        </Field>
      </div>

      <Field label="Status" htmlFor="status">
        <Select
          id="status"
          value={searchParams.get("status") ?? ""}
          onChange={(event) => apply({ status: event.target.value })}
        >
          <option value="">Todos</option>
          {BILLING_STATUSES.map((status) => (
            <option key={status.value} value={status.value}>
              {status.label}
            </option>
          ))}
        </Select>
      </Field>

      <Button type="submit" disabled={isPending}>
        {isPending ? "Filtrando…" : "Filtrar"}
      </Button>

      {searchParams.toString() ? (
        <Button
          variant="ghost"
          onClick={() => startTransition(() => router.push("/cobrancas"))}
        >
          Limpar
        </Button>
      ) : null}
    </form>
  );
}
