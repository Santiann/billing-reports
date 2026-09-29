import type { ReactNode } from "react";

/**
 * A state label.
 *
 * The tones come from the domain's tokens — overdue, paid, pending — and not from
 * a generic palette. That is what makes the same colour mean the same thing in the
 * listing, on the detail page and in the report.
 */

export type BadgeTone =
  | "neutral"
  | "overdue"
  | "paid"
  | "pending"
  | "accent"
  // `positive` looks the same as `paid` and exists so the code does not lie: an
  // ACTIVE customer is not a paid customer. Sharing the colour is right — green
  // means the same thing on both screens — but writing `tone="paid"` on a customer
  // would send the next reader looking for a payment that does not exist.
  | "positive";

const TONES: Record<BadgeTone, string> = {
  neutral: "bg-sunken text-ink-muted",
  overdue: "bg-overdue-soft text-overdue",
  paid: "bg-paid-soft text-paid",
  pending: "bg-pending-soft text-pending",
  accent: "bg-accent-soft text-accent",
  positive: "bg-paid-soft text-paid",
};

export function Badge({
  tone = "neutral",
  children,
}: {
  tone?: BadgeTone;
  children: ReactNode;
}) {
  return (
    <span
      className={`inline-flex items-center rounded-sm px-2 py-0.5 text-xs font-medium ${TONES[tone]}`}
    >
      {children}
    </span>
  );
}
