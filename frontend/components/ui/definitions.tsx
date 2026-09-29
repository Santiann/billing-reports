import type { ReactNode } from "react";

/**
 * The field list of one record — the customer's or the billing's detail panel.
 *
 * A `<dl>` and not a table: what exists here is the label and value of a single
 * record, and that is what a definition list describes. A screen reader announces
 * the label together with the value without needing a column header.
 *
 * Money values pass `mono` so they land in the same family as the table's columns:
 * the same number has to look like the same number on both screens.
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
