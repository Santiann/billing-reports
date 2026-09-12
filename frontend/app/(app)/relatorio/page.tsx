import Link from "next/link";

import { BillingStatusBadge } from "@/components/billings/billing-status-badge";
import { ReportExport } from "@/components/reports/report-export";
import { ReportFilters } from "@/components/reports/report-filters";
import { ReportTotalsPanel } from "@/components/reports/report-totals";
import { Card } from "@/components/ui/card";
import { PageHeader } from "@/components/ui/page-header";
import { Pagination } from "@/components/ui/pagination";
import { Table, TBody, TD, TEmpty, TH, THead, TR } from "@/components/ui/table";
import { getCustomer } from "@/lib/customers";
import { formatCurrency, formatDate } from "@/lib/format";
import { getBillingReport } from "@/lib/reports";
import type { Customer } from "@/types/customer";

type PageProps = {
  searchParams: Promise<Record<string, string | undefined>>;
};

const COLUMNS = [
  { key: "issue_date", label: "Emissão", sortable: true },
  { key: "due_date", label: "Vencimento", sortable: true },
  { key: "original_amount", label: "Valor original", sortable: true, numeric: true },
  { key: "interest_amount", label: "Juros", sortable: true, numeric: true },
  { key: "updated_amount", label: "Atualizado", sortable: true, numeric: true },
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

  const isCurrent = (params.sort ?? "due_date") === column;
  const nextDirection =
    isCurrent && (params.direction ?? "desc") === "asc" ? "desc" : "asc";

  query.set("sort", column);
  query.set("direction", nextDirection);

  return `/relatorio?${query.toString()}`;
}

export default async function ReportPage({ searchParams }: PageProps) {
  const params = await searchParams;
  const report = await getBillingReport(params);

  // O seletor precisa do nome para reexibir o cliente filtrado; a URL só
  // carrega o id.
  let selectedCustomer: Customer | undefined;

  if (report.filters.customer_id) {
    try {
      selectedCustomer = await getCustomer(String(report.filters.customer_id));
    } catch {
      // Cliente removido não pode derrubar o relatório inteiro.
    }
  }

  const currentSort = report.filters.sort;
  const currentDirection = report.filters.direction;

  return (
    <div>
      <PageHeader
        title="Relatório de faturamento"
        action={
          /* Os filtros vêm do backend, já normalizados: o arquivo sai com o
             mesmo recorte que a tela está mostrando. */
          <ReportExport
            filters={report.filters}
            info={report.export}
            count={report.totals.count}
          />
        }
      />

      <Card className="mb-4 p-4">
        <ReportFilters selectedCustomer={selectedCustomer} />
      </Card>

      <ReportTotalsPanel totals={report.totals} />

      <Card className="overflow-hidden">
        <Table label="Cobranças do período">
          <THead>
            <TH>Cliente</TH>
            <TH>Descrição</TH>
            {COLUMNS.map((column) => {
              const numeric = "numeric" in column && column.numeric;
              const ativa = currentSort === column.key;

              return (
                <TH
                  key={column.key}
                  numeric={numeric}
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
                    {/* A seta ocupa lugar mesmo inativa: sem isso a coluna
                        salta de largura a cada troca de ordenação. */}
                    <span aria-hidden className={ativa ? "" : "opacity-0"}>
                      {currentDirection === "asc" ? "↑" : "↓"}
                    </span>
                  </Link>
                </TH>
              );
            })}
            <TH numeric>Pago</TH>
            <TH>Status</TH>
          </THead>

          <TBody>
            {report.data.length === 0 ? (
              <TEmpty colSpan={9}>
                Nenhuma cobrança no período e filtros selecionados.
              </TEmpty>
            ) : (
              report.data.map((billing) => (
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
                    {formatDate(billing.issue_date)}
                  </TD>
                  <TD numeric className="text-ink-muted">
                    {formatDate(billing.due_date)}
                  </TD>
                  <TD numeric className="text-ink-muted">
                    {formatCurrency(billing.original_amount)}
                  </TD>
                  <TD
                    numeric
                    className={
                      Number(billing.interest_amount) > 0
                        ? "text-overdue"
                        : "text-ink-faint"
                    }
                  >
                    {formatCurrency(billing.interest_amount)}
                  </TD>
                  <TD numeric className="font-medium text-ink">
                    {formatCurrency(billing.updated_amount)}
                  </TD>
                  <TD numeric className="text-ink-muted">
                    {billing.status === "paid"
                      ? formatCurrency(billing.paid_amount)
                      : "—"}
                  </TD>
                  <TD>
                    <BillingStatusBadge billing={billing} />
                  </TD>
                </TR>
              ))
            )}
          </TBody>
        </Table>

        <Pagination
          meta={report.meta}
          basePath="/relatorio"
          searchParams={params}
        />
      </Card>
    </div>
  );
}
