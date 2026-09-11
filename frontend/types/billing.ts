import type { Customer } from "@/types/customer";

export type BillingStatus = "pending" | "paid";

export type Billing = {
  id: number;
  description: string;
  /** Decimal como string: preserva o centavo que float perderia. */
  original_amount: string;
  monthly_interest_rate: string;
  issue_date: string;
  due_date: string;
  payment_date: string | null;
  status: BillingStatus;
  status_label: string;
  /** Vencida é derivada (pendente + vencimento passado), não gravada. */
  is_overdue: boolean;
  paid_amount: string | null;
  paid_interest_amount: string | null;
  /**
   * Juros e valor atualizado calculados no backend.
   *
   * Na listagem vêm do próprio SELECT (face SQL do InterestCalculator); numa
   * cobrança isolada, da face PHP. Há teste no backend afirmando que os dois
   * caminhos dão o mesmo número até o centavo.
   *
   * Para cobrança paga são os valores congelados, nunca recalculados.
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
