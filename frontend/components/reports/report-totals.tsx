import { formatCurrency } from "@/lib/format";
import type { ReportTotals } from "@/types/report";

/**
 * Os totais vêm de uma consulta de agregação sobre o conjunto filtrado
 * inteiro. Não são a soma das linhas exibidas: na página 3 de um relatório de
 * mil cobranças, somar a página daria um número sem significado.
 */
export function ReportTotalsPanel({ totals }: { totals: ReportTotals }) {
  const cards: ReadonlyArray<{
    label: string;
    value: string;
    tone?: "neutral" | "interest" | "received" | "pending";
  }> = [
    { label: "Cobranças", value: totals.count.toLocaleString("pt-BR") },
    { label: "Valor original", value: formatCurrency(totals.original_amount) },
    { label: "Total de juros", value: formatCurrency(totals.interest_amount), tone: "interest" },
    { label: "Valor atualizado", value: formatCurrency(totals.updated_amount) },
    { label: "Recebido", value: formatCurrency(totals.paid_amount), tone: "received" },
    { label: "Pendente", value: formatCurrency(totals.pending_amount), tone: "pending" },
  ];

  const toneClass = {
    neutral: "text-slate-900",
    interest: "text-red-700",
    received: "text-emerald-700",
    pending: "text-amber-700",
  } as const;

  return (
    <dl className="mb-4 grid gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-3 lg:grid-cols-6">
      {cards.map((card) => (
        <div key={card.label} className="bg-white px-4 py-3">
          <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">
            {card.label}
          </dt>
          <dd
            className={`mt-1 font-semibold ${toneClass[card.tone ?? "neutral"]}`}
          >
            {card.value}
          </dd>
        </div>
      ))}
    </dl>
  );
}
