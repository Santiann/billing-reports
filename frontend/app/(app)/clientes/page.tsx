import Link from "next/link";

import { CustomerFilters } from "@/components/customers/customer-filters";
import { Badge } from "@/components/ui/badge";
import { buttonClasses } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Feedback } from "@/components/ui/feedback";
import { PageHeader } from "@/components/ui/page-header";
import { Pagination } from "@/components/ui/pagination";
import { Table, TBody, TD, TEmpty, TH, THead, TR } from "@/components/ui/table";
import { listCustomers } from "@/lib/customers";
import { getSessionUser } from "@/lib/session-user";

type PageProps = {
  searchParams: Promise<Record<string, string | undefined>>;
};

const SORTABLE = [
  { key: "name", label: "Nome" },
  { key: "document", label: "Documento" },
  { key: "email", label: "E-mail" },
] as const;

function sortHref(
  params: Record<string, string | undefined>,
  column: string,
): string {
  const query = new URLSearchParams();

  for (const [key, value] of Object.entries(params)) {
    if (value && key !== "page" && key !== "sucesso") {
      query.set(key, value);
    }
  }

  // Clicar na coluna já ordenada inverte a direção.
  const isCurrent = (params.sort ?? "name") === column;
  const nextDirection =
    isCurrent && (params.direction ?? "asc") === "asc" ? "desc" : "asc";

  query.set("sort", column);
  query.set("direction", nextDirection);

  return `/clientes?${query.toString()}`;
}

export default async function CustomersPage({ searchParams }: PageProps) {
  const params = await searchParams;

  const customers = await listCustomers({
    search: params.search,
    status: params.status,
    sort: params.sort,
    direction: params.direction,
    page: params.page,
    per_page: params.per_page,
  });

  const { can_write: podeEscrever } = await getSessionUser();

  const currentSort = params.sort ?? "name";
  const currentDirection = params.direction ?? "asc";

  return (
    <div>
      <PageHeader
        title="Clientes"
        action={
          podeEscrever ? (
            <div className="flex flex-wrap gap-2">
              <Link
                href="/clientes/importar"
                className={buttonClasses({ variant: "secondary" })}
              >
                Importar CSV
              </Link>
              <Link href="/clientes/novo" className={buttonClasses()}>
                Novo cliente
              </Link>
            </div>
          ) : null
        }
      />

      <Feedback code={params.sucesso} />

      <Card className="mb-4 p-4">
        <CustomerFilters />
      </Card>

      <Card className="overflow-hidden">
        <Table label="Clientes cadastrados">
          <THead>
            {SORTABLE.map((column) => {
              const ativa = currentSort === column.key;

              return (
                <TH
                  key={column.key}
                  aria-sort={
                    ativa
                      ? currentDirection === "asc"
                        ? "ascending"
                        : "descending"
                      : undefined
                  }
                >
                  <Link
                    href={sortHref(params, column.key)}
                    className={
                      "inline-flex items-center gap-1 transition-colors hover:text-ink " +
                      (ativa ? "text-ink" : "")
                    }
                  >
                    {column.label}
                    {/* A seta ocupa lugar mesmo inativa: sem isso o cabeçalho
                        salta de largura a cada troca de ordenação. */}
                    <span aria-hidden className={ativa ? "" : "opacity-0"}>
                      {currentDirection === "asc" ? "↑" : "↓"}
                    </span>
                  </Link>
                </TH>
              );
            })}
            <TH>Status</TH>
            <TH numeric>Ações</TH>
          </THead>

          <TBody>
            {customers.data.length === 0 ? (
              <TEmpty colSpan={5}>
                Nenhum cliente encontrado com esses filtros.
              </TEmpty>
            ) : (
              customers.data.map((customer) => (
                <TR key={customer.id}>
                  <TD>
                    <Link
                      href={`/clientes/${customer.id}`}
                      className="font-medium text-ink hover:underline"
                    >
                      {customer.name}
                    </Link>
                  </TD>
                  <TD className="font-mono text-ink-muted">
                    {customer.document}
                  </TD>
                  <TD className="text-ink-muted">{customer.email}</TD>
                  <TD>
                    <Badge
                      tone={customer.status === "active" ? "positive" : "neutral"}
                    >
                      {customer.status_label}
                    </Badge>
                  </TD>
                  <TD numeric>
                    {podeEscrever ? (
                      <Link
                        href={`/clientes/${customer.id}/editar`}
                        className="font-sans text-sm text-accent hover:underline"
                      >
                        Editar
                      </Link>
                    ) : (
                      <span className="text-ink-faint">—</span>
                    )}
                  </TD>
                </TR>
              ))
            )}
          </TBody>
        </Table>

        <Pagination
          meta={customers.meta}
          basePath="/clientes"
          searchParams={params}
        />
      </Card>
    </div>
  );
}
