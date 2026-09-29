import { Badge } from "@/components/ui/badge";
import { formatCurrency } from "@/lib/format";
import type { DashboardPeriod } from "@/types/dashboard";

/**
 * The current month's indicators.
 *
 * PROPORTIONAL figures on the values, not tabular: `tabular-nums` gives every
 * digit the width of a zero, which lines up a column but loosens a large number
 * standing on its own. Vertical alignment is a table's problem.
 */
export function PeriodSummary({ period }: { period: DashboardPeriod }) {
  const indicators = [
    {
      label: "Faturado no mês",
      value: formatCurrency(period.original_amount),
      hint: `${period.count.toLocaleString("pt-BR")} cobranças`,
    },
    {
      label: "Recebido",
      value: formatCurrency(period.received_amount),
      tone: "text-paid",
      hint: "valor congelado no pagamento",
    },
    {
      label: "A receber",
      value: formatCurrency(period.pending_amount),
      tone: "text-pending",
      hint: "já com os juros de hoje",
    },
    {
      label: "Juros acumulados",
      value: formatCurrency(period.interest_amount),
      tone: "text-overdue",
      hint: `${period.overdue_count.toLocaleString("pt-BR")} vencidas`,
    },
  ] as const;

  return (
    <section className="mb-6">
      <div className="mb-3 flex flex-wrap items-baseline gap-3">
        <h2 className="text-xs font-semibold uppercase tracking-widest text-ink-muted">
          {period.label}
        </h2>
        {period.overdue_count > 0 ? (
          <Badge tone="overdue">
            {period.overdue_count.toLocaleString("pt-BR")} vencidas
          </Badge>
        ) : null}
      </div>

      <dl className="grid gap-px overflow-hidden rounded-lg border border-rule bg-rule sm:grid-cols-2 lg:grid-cols-4">
        {indicators.map((indicator) => (
          <div key={indicator.label} className="bg-surface px-4 py-4">
            <dt className="text-xs font-medium uppercase tracking-wide text-ink-muted">
              {indicator.label}
            </dt>
            <dd
              className={`mt-1 text-2xl font-semibold ${"tone" in indicator ? indicator.tone : "text-ink"}`}
            >
              {indicator.value}
            </dd>
            <p className="mt-1 text-xs text-ink-faint">{indicator.hint}</p>
          </div>
        ))}
      </dl>
    </section>
  );
}
