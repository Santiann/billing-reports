import { formatCurrency } from "@/lib/format";
import type { ReportTotals } from "@/types/report";

/**
 * Os seis totalizadores do recorte.
 *
 * Vêm de uma consulta de agregação sobre o conjunto filtrado inteiro. Não são a
 * soma das linhas exibidas: na página 3 de um relatório de mil cobranças, somar
 * a página daria um número sem significado.
 *
 * Fileira de indicadores, e não gráfico: seis números de escalas diferentes —
 * uma contagem e cinco valores — não têm eixo comum, e um gráfico de barras
 * aqui seria decoração sobre dado que já se lê direto.
 *
 * Os valores usam figura PROPORCIONAL, ao contrário das colunas da tabela.
 * `tabular-nums` dá a todo dígito a largura do zero, o que é o que faz uma
 * coluna alinhar — e o que faz um número grande e isolado parecer frouxo.
 * Alinhamento vertical é o problema da tabela, não do indicador.
 */

type Indicador = {
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
  const indicadores: ReadonlyArray<Indicador> = [
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
      {indicadores.map((indicador) => (
        <div key={indicador.label} className="bg-surface px-4 py-3">
          <dt className="text-xs font-medium uppercase tracking-wide text-ink-muted">
            {indicador.label}
          </dt>
          <dd
            className={`mt-1 text-xl font-semibold ${TONES[indicador.tone ?? "ink"]}`}
          >
            {indicador.value}
          </dd>
        </div>
      ))}
    </dl>
  );
}
