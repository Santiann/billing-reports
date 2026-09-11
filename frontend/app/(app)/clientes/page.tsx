import Link from "next/link";

import { CustomerFilters } from "@/components/customers/customer-filters";
import { Feedback } from "@/components/ui/feedback";
import { Pagination } from "@/components/ui/pagination";
import { listCustomers } from "@/lib/customers";

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

  const currentSort = params.sort ?? "name";
  const currentDirection = params.direction ?? "asc";

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">Clientes</h1>

        <Link
          href="/clientes/novo"
          className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
        >
          Novo cliente
        </Link>
      </div>

      <Feedback code={params.sucesso} />

      <div className="mb-4 rounded-lg border border-slate-200 bg-white p-4">
        <CustomerFilters />
      </div>

      <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[40rem] text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-600">
              <tr>
                {SORTABLE.map((column) => (
                  <th key={column.key} scope="col" className="px-4 py-3 font-medium">
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
                <th scope="col" className="px-4 py-3 font-medium">
                  Status
                </th>
                <th scope="col" className="px-4 py-3 text-right font-medium">
                  Ações
                </th>
              </tr>
            </thead>

            <tbody className="divide-y divide-slate-100">
              {customers.data.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-4 py-10 text-center text-slate-500">
                    Nenhum cliente encontrado com esses filtros.
                  </td>
                </tr>
              ) : (
                customers.data.map((customer) => (
                  <tr key={customer.id} className="hover:bg-slate-50">
                    <td className="px-4 py-3 font-medium text-slate-900">
                      <Link
                        href={`/clientes/${customer.id}`}
                        className="hover:underline"
                      >
                        {customer.name}
                      </Link>
                    </td>
                    <td className="px-4 py-3 text-slate-600">{customer.document}</td>
                    <td className="px-4 py-3 text-slate-600">{customer.email}</td>
                    <td className="px-4 py-3">
                      <span
                        className={
                          customer.status === "active"
                            ? "rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"
                            : "rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"
                        }
                      >
                        {customer.status_label}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <Link
                        href={`/clientes/${customer.id}/editar`}
                        className="text-sm font-medium text-slate-700 hover:underline"
                      >
                        Editar
                      </Link>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        <Pagination
          meta={customers.meta}
          basePath="/clientes"
          searchParams={params}
        />
      </div>
    </div>
  );
}
