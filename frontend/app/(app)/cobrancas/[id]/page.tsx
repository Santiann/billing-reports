import Link from "next/link";
import { notFound } from "next/navigation";

import { BillingAuditTrail } from "@/components/billings/billing-audit-trail";
import { BillingStatusBadge } from "@/components/billings/billing-status-badge";
import { PaymentForm } from "@/components/billings/payment-form";
import { ReversalForm } from "@/components/billings/reversal-form";
import { buttonClasses } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Definitions } from "@/components/ui/definitions";
import { Feedback } from "@/components/ui/feedback";
import { PageHeader } from "@/components/ui/page-header";
import { ApiError } from "@/lib/api";
import { getBilling, getBillingAudit } from "@/lib/billings";
import { getSessionUser } from "@/lib/session-user";
import { formatCurrency, formatDate, formatPercent } from "@/lib/format";
import type { Billing } from "@/types/billing";
import type { BillingAuditEntry } from "@/types/billing-audit";
import type { Paginated } from "@/types/pagination";

type PageProps = {
  params: Promise<{ id: string }>;
  searchParams: Promise<Record<string, string | undefined>>;
};

export default async function BillingPage({ params, searchParams }: PageProps) {
  const { id } = await params;
  const query = await searchParams;

  let billing: Billing;
  let trail: Paginated<BillingAuditEntry>;

  try {
    // In parallel: the trail does not depend on the billing to be fetched, and in
    // sequence the page would wait for the two responses one after the other.
    [billing, trail] = await Promise.all([getBilling(id), getBillingAudit(id)]);
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      notFound();
    }

    throw error;
  }

  const isPaid = billing.status === "paid";
  const { can_write: canWrite } = await getSessionUser();

  return (
    <div>
      <PageHeader
        title={billing.description}
        voltar={{ href: "/cobrancas", label: "Cobranças" }}
        badge={<BillingStatusBadge billing={billing} />}
        action={
          isPaid || !canWrite ? undefined : (
            <Link
              href={`/cobrancas/${billing.id}/editar`}
              className={buttonClasses({ variant: "secondary" })}
            >
              Editar
            </Link>
          )
        }
      />

      <Feedback code={query.sucesso} />

      <Definitions
        items={[
          {
            label: "Cliente",
            value: billing.customer ? (
              <Link
                href={`/clientes/${billing.customer.id}`}
                className="text-accent hover:underline"
              >
                {billing.customer.name}
              </Link>
            ) : (
              "—"
            ),
          },
          {
            label: "Taxa de juros",
            value: formatPercent(billing.monthly_interest_rate),
            mono: true,
          },
          { label: "Emissão", value: formatDate(billing.issue_date), mono: true },
          { label: "Vencimento", value: formatDate(billing.due_date), mono: true },
        ]}
      />

      {isPaid ? (
        <section className="mt-8">
          <h2 className="mb-3 text-xs font-semibold uppercase tracking-widest text-ink-muted">
            Pagamento
          </h2>

          {/* Amounts frozen on the payment date, read from the columns
              gravadas — nunca recalculados. */}
          <Definitions
            columns={3}
            items={[
              {
                label: "Data",
                value: formatDate(billing.payment_date),
                mono: true,
              },
              {
                label: "Juros no pagamento",
                value: formatCurrency(billing.paid_interest_amount),
                mono: true,
                tone: Number(billing.paid_interest_amount) > 0 ? "overdue" : "ink",
              },
              {
                label: "Valor pago",
                value: formatCurrency(billing.paid_amount),
                mono: true,
                tone: "paid",
              },
            ]}
          />

          <p className="mt-3 text-sm text-ink-muted">
            Cobrança paga não acumula juros: estes valores foram congelados na
            data do pagamento e não mudam mais — a menos que o pagamento seja
            estornado.
          </p>

          {/* Hidden for the read-only role; the endpoint refuses either
              forma. */}
          {canWrite ? (
            <Card className="mt-8">
              <CardHeader title="Estornar pagamento" />
              <CardBody className="p-6">
                <ReversalForm billingId={billing.id} />
              </CardBody>
            </Card>
          ) : null}
        </section>
      ) : (
        <>
          <section className="mt-8">
            <h2 className="mb-3 text-xs font-semibold uppercase tracking-widest text-ink-muted">
              Valor atualizado
            </h2>

            {/* Computed in real time: compound interest over the days
                atraso, nunca gravado no banco enquanto não há pagamento. */}
            <Definitions
              columns={3}
              items={[
                {
                  label: "Valor original",
                  value: formatCurrency(billing.original_amount),
                  mono: true,
                },
                {
                  label: "Juros até hoje",
                  value: formatCurrency(billing.interest_amount),
                  mono: true,
                  tone: Number(billing.interest_amount) > 0 ? "overdue" : "ink",
                },
                {
                  label: "Total atualizado",
                  value: formatCurrency(billing.updated_amount),
                  mono: true,
                },
              ]}
            />
          </section>

          {/* The form is hidden for the read-only role; the endpoint refuses
              de qualquer forma. */}
          {canWrite ? (
            <Card className="mt-8">
              <CardHeader title="Registrar pagamento" />
              <CardBody className="p-6">
                <PaymentForm billing={billing} />
              </CardBody>
            </Card>
          ) : null}
        </>
      )}

      <BillingAuditTrail entries={trail.data} total={trail.meta.total} />
    </div>
  );
}
