import Link from "next/link";

import { ReportExport } from "@/components/reports/report-export";
import { ReportFilters } from "@/components/reports/report-filters";
import { ReportTotalsPanel } from "@/components/reports/report-totals";
import { Pagination } from "@/components/ui/pagination";
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
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">
          Relatório de faturamento
        </h1>

        {/* Os filtros vêm do backend, já normalizados: o arquivo sai com o
            mesmo recorte que a tela está mostrando. */}
        <ReportExport filters={report.filters} />
      </div>

      <div className="mb-4 rounded-lg border border-slate-200 bg-white p-4">
        <ReportFilters selectedCustomer={selectedCustomer} />
      </div>

      <ReportTotalsPanel totals={report.totals} />

      <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[64rem] text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-600">
              <tr>
                <th scope="col" className="px-4 py-3 font-medium">Cliente</th>
                <th scope="col" className="px-4 py-3 font-medium">Descrição</th>
                {COLUMNS.map((column) => (
                  <th
                    key={column.key}
                    scope="col"
                    className={`px-4 py-3 font-medium ${"numeric" in column && column.numeric ? "text-right" : ""}`}
                  >
                    <Link
                      href={sortHref(params, column.key)}
                      className="inline-flex items-center gap-1 transition hover:text-slate-900"
                    >
                      {column.label}
                      {currentSort === column.key ? (
                        <span aria-hidden>
                          {currentDirection === "asc" ? "↑" : "↓"}
                        </span>
                      ) : null}
                    </Link>
                  </th>
                ))}
                <th scope="col" className="px-4 py-3 text-right font-medium">Pago</th>
                <th scope="col" className="px-4 py-3 font-medium">Status</th>
              </tr>
            </thead>

            <tbody className="divide-y divide-slate-100">
              {report.data.length === 0 ? (
                <tr>
                  <td colSpan={9} className="px-4 py-10 text-center text-slate-500">
                    Nenhuma cobrança no período e filtros selecionados.
                  </td>
                </tr>
              ) : (
                report.data.map((billing) => (
                  <tr key={billing.id} className="hover:bg-slate-50">
                    <td className="px-4 py-3 text-slate-600">
                      {billing.customer?.name ?? "—"}
                    </td>
                    <td className="px-4 py-3 text-slate-900">
                      <Link
                        href={`/cobrancas/${billing.id}`}
                        className="font-medium hover:underline"
                      >
                        {billing.description}
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-slate-600">
                      {formatDate(billing.issue_date)}
                    </td>
                    <td className="px-4 py-3 text-slate-600">
                      {formatDate(billing.due_date)}
                    </td>
                    <td className="px-4 py-3 text-right text-slate-600">
                      {formatCurrency(billing.original_amount)}
                    </td>
                    <td
                      className={`px-4 py-3 text-right ${Number(billing.interest_amount) > 0 ? "text-red-700" : "text-slate-500"}`}
                    >
                      {formatCurrency(billing.interest_amount)}
                    </td>
                    <td className="px-4 py-3 text-right font-medium text-slate-900">
                      {formatCurrency(billing.updated_amount)}
                    </td>
                    <td className="px-4 py-3 text-right text-slate-600">
                      {billing.status === "paid"
                        ? formatCurrency(billing.paid_amount)
                        : "—"}
                    </td>
                    <td className="px-4 py-3">
                      {billing.is_overdue ? (
                        <span className="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">
                          Vencida
                        </span>
                      ) : billing.status === "paid" ? (
                        <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">
                          Paga
                        </span>
                      ) : (
                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                          Pendente
                        </span>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        <Pagination
          meta={report.meta}
          basePath="/relatorio"
          searchParams={params}
        />
      </div>
    </div>
  );
}
