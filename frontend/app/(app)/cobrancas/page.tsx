import Link from "next/link";

import { BillingFilters } from "@/components/billings/billing-filters";
import { BillingStatusBadge } from "@/components/billings/billing-status-badge";
import { buttonClasses } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Feedback } from "@/components/ui/feedback";
import { PageHeader } from "@/components/ui/page-header";
import { Pagination } from "@/components/ui/pagination";
import { Table, TBody, TD, TEmpty, TH, THead, TR } from "@/components/ui/table";
import { listBillings } from "@/lib/billings";
import { getSessionUser } from "@/lib/session-user";
import { formatCurrency, formatDate } from "@/lib/format";

type PageProps = {
  searchParams: Promise<Record<string, string | undefined>>;
};

const SORTABLE = [
  { key: "due_date", label: "Vencimento", numeric: false },
  { key: "issue_date", label: "Emissão", numeric: false },
  { key: "original_amount", label: "Valor", numeric: true },
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

  const isCurrent = params.sort === column;
  const nextDirection =
    isCurrent && (params.direction ?? "desc") === "asc" ? "desc" : "asc";

  query.set("sort", column);
  query.set("direction", nextDirection);

  return `/cobrancas?${query.toString()}`;
}

export default async function BillingsPage({ searchParams }: PageProps) {
  const params = await searchParams;

  const billings = await listBillings({
    customer_id: params.customer_id,
    status: params.status,
    search: params.search,
    sort: params.sort,
    direction: params.direction,
    page: params.page,
    per_page: params.per_page,
  });

  const { can_write: podeEscrever } = await getSessionUser();

  return (
    <div>
      <PageHeader
        title="Cobranças"
        action={
          podeEscrever ? (
            <div className="flex flex-wrap gap-2">
              <Link
                href="/cobrancas/importar"
                className={buttonClasses({ variant: "secondary" })}
              >
                Importar CSV
              </Link>
              <Link href="/cobrancas/nova" className={buttonClasses()}>
                Nova cobrança
              </Link>
            </div>
          ) : null
        }
      />

      <Feedback code={params.sucesso} />

      <Card className="mb-4 p-4">
        <BillingFilters />
      </Card>

      <Card className="overflow-hidden">
        <Table label="Cobranças cadastradas">
          <THead>
            <TH>Cliente</TH>
            <TH>Descrição</TH>
            {SORTABLE.map((column) => {
              const ativa = params.sort === column.key;

              return (
                <TH
                  key={column.key}
                  numeric={column.numeric}
                  aria-sort={
                    ativa
                      ? (params.direction ?? "desc") === "asc"
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
                    {/* A seta ocupa lugar mesmo inativa: sem isso a coluna
                        salta de largura a cada troca de ordenação. */}
                    <span aria-hidden className={ativa ? "" : "opacity-0"}>
                      {(params.direction ?? "desc") === "asc" ? "↑" : "↓"}
                    </span>
                  </Link>
                </TH>
              );
            })}
            <TH numeric>Atualizado</TH>
            <TH>Status</TH>
            <TH numeric>Ações</TH>
          </THead>

          <TBody>
            {billings.data.length === 0 ? (
              <TEmpty colSpan={8}>
                Nenhuma cobrança encontrada com esses filtros.
              </TEmpty>
            ) : (
              billings.data.map((billing) => (
                <TR key={billing.id}>
                  <TD className="text-ink-muted">
                    {billing.customer?.name ?? "—"}
                  </TD>
                  <TD>
                    <Link
                      href={`/cobrancas/${billing.id}`}
                      className="font-medium text-ink hover:underline"
                    >
                      {billing.description}
                    </Link>
                  </TD>
                  <TD numeric className="text-ink-muted">
                    {formatDate(billing.due_date)}
                  </TD>
                  <TD numeric className="text-ink-muted">
                    {formatDate(billing.issue_date)}
                  </TD>
                  <TD numeric className="text-ink-muted">
                    {formatCurrency(billing.original_amount)}
                  </TD>
                  <TD numeric>
                    <span className="font-medium text-ink">
                      {formatCurrency(billing.updated_amount)}
                    </span>
                    {Number(billing.interest_amount) > 0 ? (
                      <span className="block text-xs text-overdue">
                        + {formatCurrency(billing.interest_amount)} de juros
                      </span>
                    ) : null}
                  </TD>
                  <TD>
                    <BillingStatusBadge billing={billing} />
                  </TD>
                  <TD numeric>
                    {billing.status === "paid" || !podeEscrever ? (
                      <span className="text-ink-faint">—</span>
                    ) : (
                      <Link
                        href={`/cobrancas/${billing.id}/editar`}
                        className="font-sans text-sm text-accent hover:underline"
                      >
                        Editar
                      </Link>
                    )}
                  </TD>
                </TR>
              ))
            )}
          </TBody>
        </Table>

        <Pagination
          meta={billings.meta}
          basePath="/cobrancas"
          searchParams={params}
        />
      </Card>
    </div>
  );
}
