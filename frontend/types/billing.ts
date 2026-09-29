import type { Customer } from "@/types/customer";

export type BillingStatus = "pending" | "paid";

export type Billing = {
  id: number;
  description: string;
  /** Decimal as a string: it preserves the cent a float would lose. */
  original_amount: string;
  monthly_interest_rate: string;
  issue_date: string;
  due_date: string;
  payment_date: string | null;
  status: BillingStatus;
  status_label: string;
  /** Overdue is derived (pending + due date passed), not stored. */
  is_overdue: boolean;
  paid_amount: string | null;
  paid_interest_amount: string | null;
  /**
   * Interest and updated amount computed on the backend.
   *
   * In the listing they come from the SELECT itself (the SQL face of
   * InterestCalculator); on a single billing, from the PHP face. There is a
   * backend test asserting both paths give the same number down to the cent.
   *
   * For a paid billing these are the frozen amounts, never recomputed.
   */
  interest_amount: string;
  updated_amount: string;
  customer?: Customer;
};

export const BILLING_STATUSES: ReadonlyArray<{
  value: BillingStatus;
  label: string;
}> = [
  { value: "pending", label: "Pendente" },
  { value: "paid", label: "Paga" },
];
