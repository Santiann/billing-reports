import Link from "next/link";

import { BillingFilters } from "@/components/billings/billing-filters";
import { Feedback } from "@/components/ui/feedback";
import { Pagination } from "@/components/ui/pagination";
import { listBillings } from "@/lib/billings";
import { formatCurrency, formatDate } from "@/lib/format";

type PageProps = {
  searchParams: Promise<Record<string, string | undefined>>;
};

const SORTABLE = [
  { key: "due_date", label: "Vencimento" },
  { key: "issue_date", label: "Emissão" },
  { key: "original_amount", label: "Valor" },
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

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">Cobranças</h1>

        <Link
          href="/cobrancas/nova"
          className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
        >
          Nova cobrança
        </Link>
      </div>

      <Feedback code={params.sucesso} />

      <div className="mb-4 rounded-lg border border-slate-200 bg-white p-4">
        <BillingFilters />
      </div>

      <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[52rem] text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-600">
              <tr>
                <th scope="col" className="px-4 py-3 font-medium">Cliente</th>
                <th scope="col" className="px-4 py-3 font-medium">Descrição</th>
                {SORTABLE.map((column) => (
                  <th key={column.key} scope="col" className="px-4 py-3 font-medium">
                    <Link
                      href={sortHref(params, column.key)}
                      className="inline-flex items-center gap-1 transition hover:text-slate-900"
                    >
                      {column.label}
                      {params.sort === column.key ? (
                        <span aria-hidden>
                          {(params.direction ?? "desc") === "asc" ? "↑" : "↓"}
                        </span>
                      ) : null}
                    </Link>
                  </th>
                ))}
                <th scope="col" className="px-4 py-3 font-medium">Status</th>
                <th scope="col" className="px-4 py-3 text-right font-medium">Ações</th>
              </tr>
            </thead>

            <tbody className="divide-y divide-slate-100">
              {billings.data.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-4 py-10 text-center text-slate-500">
                    Nenhuma cobrança encontrada com esses filtros.
                  </td>
                </tr>
              ) : (
                billings.data.map((billing) => (
                  <tr key={billing.id} className="hover:bg-slate-50">
                    <td className="px-4 py-3 text-slate-600">
                      {billing.customer?.name ?? "—"}
                    </td>
                    <td className="px-4 py-3 font-medium text-slate-900">
                      <Link
                        href={`/cobrancas/${billing.id}`}
                        className="hover:underline"
                      >
                        {billing.description}
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-slate-600">
                      {formatDate(billing.due_date)}
                    </td>
                    <td className="px-4 py-3 text-slate-600">
                      {formatDate(billing.issue_date)}
                    </td>
                    <td className="px-4 py-3 text-slate-600">
                      {formatCurrency(billing.original_amount)}
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
                    <td className="px-4 py-3 text-right">
                      {billing.status === "paid" ? (
                        <span className="text-sm text-slate-400">—</span>
                      ) : (
                        <Link
                          href={`/cobrancas/${billing.id}/editar`}
                          className="text-sm font-medium text-slate-700 hover:underline"
                        >
                          Editar
                        </Link>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        <Pagination
          meta={billings.meta}
          basePath="/cobrancas"
          searchParams={params}
        />
      </div>
    </div>
  );
}
