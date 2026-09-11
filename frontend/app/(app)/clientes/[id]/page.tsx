import Link from "next/link";
import { notFound } from "next/navigation";

import { Feedback } from "@/components/ui/feedback";
import { ApiError } from "@/lib/api";
import { getCustomer } from "@/lib/customers";
import type { Customer } from "@/types/customer";

type PageProps = {
  params: Promise<{ id: string }>;
  searchParams: Promise<Record<string, string | undefined>>;
};

export default async function CustomerPage({ params, searchParams }: PageProps) {
  const { id } = await params;
  const query = await searchParams;

  let customer: Customer;

  try {
    customer = await getCustomer(id);
  } catch (error) {
    // 404 da API vira 404 do Next, não erro genérico.
    if (error instanceof ApiError && error.status === 404) {
      notFound();
    }

    throw error;
  }

  const fields: ReadonlyArray<{ label: string; value: string }> = [
    { label: "Nome", value: customer.name },
    { label: "Documento", value: customer.document },
    { label: "E-mail", value: customer.email },
    { label: "Status", value: customer.status_label },
  ];

  return (
    <div>
      <nav className="mb-2 text-sm">
        <Link href="/clientes" className="text-slate-600 hover:text-slate-900">
          ← Clientes
        </Link>
      </nav>

      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-xl font-semibold text-slate-900">{customer.name}</h1>

        <Link
          href={`/clientes/${customer.id}/editar`}
          className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100"
        >
          Editar
        </Link>
      </div>

      <Feedback code={query.sucesso} />

      <dl className="grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-2">
        {fields.map((field) => (
          <div key={field.label} className="bg-white px-4 py-3">
            <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">
              {field.label}
            </dt>
            <dd className="mt-1 text-slate-900">{field.value}</dd>
          </div>
        ))}
      </dl>
    </div>
  );
}
