"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useTransition } from "react";

import { Button } from "@/components/ui/button";
import { Field, Input, Select } from "@/components/ui/field";
import { CUSTOMER_STATUSES } from "@/types/customer";

/**
 * Os filtros vivem na URL, não em estado de componente: assim a página é
 * compartilhável, sobrevive ao refresh e o Server Component consegue montar a
 * consulta já filtrada no servidor.
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

    // Filtro novo sempre volta para a primeira página: manter `page=7` depois
    // de filtrar costuma cair numa página que não existe mais.
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
          {/* Não-controlado, com a URL como fonte da verdade. A `key` remonta o
              campo quando o parâmetro muda por fora (voltar do browser, botão
              Limpar), que é o que um useEffect de sincronia faria — só que sem
              estado duplicado. */}
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
