"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useTransition } from "react";

import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/field";
import { CUSTOMER_STATUSES } from "@/types/customer";

/**
 * The filters live in the URL, not in component state: that way the page is
 * shareable, survives a refresh, and the Server Component can build the already
 * filtered query on the server.
 */
export function CustomerFilters() {
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

    // A new filter always goes back to the first page: keeping `page=7` after
    // filtering usually lands on a page that no longer exists.
    params.delete("page");
    params.delete("sucesso");

    startTransition(() => {
      router.push(`/clientes?${params.toString()}`);
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
          {/* Uncontrolled, with the URL as the source of truth. The `key` remounts
              the field when the parameter changes from outside (browser back, the
              Limpar button), which is what a syncing useEffect would do — only
              without duplicated state. */}
          <Input
            id="search"
            name="search"
            type="search"
            key={currentSearch}
            defaultValue={currentSearch}
            placeholder="Nome, documento ou e-mail"
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
          {CUSTOMER_STATUSES.map((status) => (
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
          onClick={() => startTransition(() => router.push("/clientes"))}
        >
          Limpar
        </Button>
      ) : null}
    </form>
  );
}
