import { formatCurrency } from "@/lib/format";
import type { ReportTotals } from "@/types/report";

/**
 * The scope's six totals.
 *
 * They come from an aggregation query over the whole filtered set. They are not the
 * sum of the rows on display: on page 3 of a thousand-billing report, summing the
 * page would give a meaningless number.
 *
 * A row of indicators, not a chart: six numbers on different scales — one count and
 * five amounts — have no common axis, and a bar chart here would be decoration over
 * data that already reads directly.
 *
 * The values use PROPORTIONAL figures, unlike the table's columns. `tabular-nums`
 * gives every digit the width of a zero, which is what makes a column line up — and
 * what makes a large, isolated number look loose. Vertical alignment is the table's
 * problem, not the indicator's.
 */

type Indicator = {
  label: string;
  value: string;
  tone?: "ink" | "overdue" | "paid" | "pending";
};

const TONES = {
  ink: "text-ink",
  overdue: "text-overdue",
  paid: "text-paid",
  pending: "text-pending",
} as const;

export function ReportTotalsPanel({ totals }: { totals: ReportTotals }) {
  const indicators: ReadonlyArray<Indicator> = [
    { label: "Cobranças", value: totals.count.toLocaleString("pt-BR") },
    { label: "Valor original", value: formatCurrency(totals.original_amount) },
    {
      label: "Total de juros",
      value: formatCurrency(totals.interest_amount),
      tone: "overdue",
    },
    { label: "Valor atualizado", value: formatCurrency(totals.updated_amount) },
    {
      label: "Recebido",
      value: formatCurrency(totals.paid_amount),
      tone: "paid",
    },
    {
      label: "Pendente",
      value: formatCurrency(totals.pending_amount),
      tone: "pending",
    },
  ];

  return (
    <dl className="mb-4 grid gap-px overflow-hidden rounded-lg border border-rule bg-rule sm:grid-cols-2 lg:grid-cols-3">
      {indicators.map((indicator) => (
        <div key={indicator.label} className="bg-surface px-4 py-3">
          <dt className="text-xs font-medium uppercase tracking-wide text-ink-muted">
            {indicator.label}
          </dt>
          <dd
            className={`mt-1 text-xl font-semibold ${TONES[indicator.tone ?? "ink"]}`}
          >
            {indicator.value}
          </dd>
        </div>
      ))}
    </dl>
  );
}
