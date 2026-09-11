import Link from "next/link";
import { notFound } from "next/navigation";

import { Feedback } from "@/components/ui/feedback";
import { ApiError } from "@/lib/api";
import { getBilling } from "@/lib/billings";
import { formatCurrency, formatDate, formatPercent } from "@/lib/format";
import type { Billing } from "@/types/billing";

type PageProps = {
  params: Promise<{ id: string }>;
  searchParams: Promise<Record<string, string | undefined>>;
};

export default async function BillingPage({ params, searchParams }: PageProps) {
  const { id } = await params;
  const query = await searchParams;

  let billing: Billing;

  try {
    billing = await getBilling(id);
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      notFound();
    }

    throw error;
  }

  const fields: ReadonlyArray<{ label: string; value: string }> = [
    { label: "Cliente", value: billing.customer?.name ?? "—" },
    { label: "Descrição", value: billing.description },
    { label: "Valor original", value: formatCurrency(billing.original_amount) },
    { label: "Taxa de juros", value: formatPercent(billing.monthly_interest_rate) },
    { label: "Emissão", value: formatDate(billing.issue_date) },
    { label: "Vencimento", value: formatDate(billing.due_date) },
  ];

  return (
    <div>
      <nav className="mb-2 text-sm">
        <Link href="/cobrancas" className="text-slate-600 hover:text-slate-900">
          ← Cobranças
        </Link>
      </nav>

      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-xl font-semibold text-slate-900">
            {billing.description}
          </h1>

          {billing.is_overdue ? (
            <span className="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">
              Vencida
            </span>
          ) : (
            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
              {billing.status_label}
            </span>
          )}
        </div>

        {billing.status === "pending" ? (
          <Link
            href={`/cobrancas/${billing.id}/editar`}
            className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100"
          >
            Editar
          </Link>
        ) : null}
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

      {billing.status === "paid" ? (
        <section className="mt-6">
          <h2 className="mb-2 text-sm font-semibold text-slate-900">
            Pagamento
          </h2>

          {/* Valores congelados na data do pagamento, lidos das colunas
              gravadas — nunca recalculados. */}
          <dl className="grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-3">
            <div className="bg-white px-4 py-3">
              <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">
                Data
              </dt>
              <dd className="mt-1 text-slate-900">
                {formatDate(billing.payment_date)}
              </dd>
            </div>
            <div className="bg-white px-4 py-3">
              <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">
                Juros no pagamento
              </dt>
              <dd className="mt-1 text-slate-900">
                {formatCurrency(billing.paid_interest_amount)}
              </dd>
            </div>
            <div className="bg-white px-4 py-3">
              <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">
                Valor pago
              </dt>
              <dd className="mt-1 font-medium text-slate-900">
                {formatCurrency(billing.paid_amount)}
              </dd>
            </div>
          </dl>
        </section>
      ) : (
        <p className="mt-6 text-sm text-slate-500">
          O registro de pagamento e o valor atualizado com juros entram na
          próxima etapa.
        </p>
      )}
    </div>
  );
}
