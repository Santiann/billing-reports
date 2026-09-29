import { Badge } from "@/components/ui/badge";
import type { Billing } from "@/types/billing";

/**
 * The billing's state as a label.
 *
 * It exists as a component because the rule appears on three screens — listing,
 * detail and report — and it has a detail that is easy to get wrong: "overdue" is
 * NOT one of the two stored statuses. It is a derived condition, pending with the
 * due date in the past, and the backend delivers it ready in `is_overdue`.
 * Repeating the ternary on each screen is how the three end up disagreeing.
 */
export function BillingStatusBadge({
  billing,
}: {
  billing: Pick<Billing, "is_overdue" | "status" | "status_label">;
}) {
  if (billing.is_overdue) {
    return <Badge tone="overdue">Vencida</Badge>;
  }

  return (
    <Badge tone={billing.status === "paid" ? "paid" : "pending"}>
      {billing.status_label}
    </Badge>
  );
}
