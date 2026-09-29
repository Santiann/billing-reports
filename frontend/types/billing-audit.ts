/**
 * One entry in a billing's audit trail.
 *
 * `from` and `to` arrive as the database keeps them: money as a decimal string, a
 * date as YYYY-MM-DD, status by its enum value, a customer by id. Formatting
 * belongs to the screen, per field — the same number has to look the same here
 * and on the detail panel.
 */
export type BillingAuditChange = {
  field: string;
  label: string;
  from: string | number | null;
  to: string | number | null;
};

export type BillingAuditEntry = {
  id: number;
  event: "updated" | "paid" | "reversed";
  event_label: string;
  /** Null when the change did not come from a request: console, command. */
  user: { id: number; name: string } | null;
  changes: BillingAuditChange[];
  created_at: string;
};
