import type { ReactNode } from "react";

/**
 * Lista de campos de um registro — a ficha do cliente ou da cobrança.
 *
 * `<dl>` e não uma tabela: o que existe aqui é rótulo e valor de um registro
 * só, e é isso que a lista de definição descreve. Leitor de tela anuncia o
 * rótulo junto do valor sem precisar de cabeçalho de coluna.
 *
 * Os valores de dinheiro passam `mono` para cair na mesma família das colunas
 * da tabela: o mesmo número precisa parecer o mesmo número nas duas telas.
 */

export type Definition = {
  label: string;
  value: ReactNode;
  mono?: boolean;
  tone?: "ink" | "overdue" | "paid";
};

const TONES = {
  ink: "text-ink",
  overdue: "text-overdue",
  paid: "text-paid",
} as const;

export function Definitions({
  items,
  columns = 2,
}: {
  items: ReadonlyArray<Definition>;
  columns?: 2 | 3;
}) {
  return (
    <dl
      className={
        "grid gap-px overflow-hidden rounded-lg border border-rule bg-rule " +
        (columns === 3 ? "sm:grid-cols-3" : "sm:grid-cols-2")
      }
    >
      {items.map((item) => (
        <div key={item.label} className="bg-surface px-4 py-3">
          <dt className="text-xs font-medium uppercase tracking-wide text-ink-muted">
            {item.label}
          </dt>
          <dd
            className={
              "mt-1 " +
              TONES[item.tone ?? "ink"] +
              (item.mono ? " font-mono tabular-nums" : "")
            }
          >
            {item.value}
          </dd>
        </div>
      ))}
    </dl>
  );
}
