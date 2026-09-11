import type { Billing } from "@/types/billing";
import type { Paginated } from "@/types/pagination";

export type ReportDateField = "issue_date" | "due_date" | "payment_date";
export type ReportStatus = "pending" | "paid" | "overdue";

/** Totalizadores do conjunto filtrado inteiro, não da página exibida. */
export type ReportTotals = {
  count: number;
  original_amount: string;
  interest_amount: string;
  updated_amount: string;
  paid_amount: string;
  pending_amount: string;
};

/** Eco dos filtros aplicados, como o backend os entendeu. */
export type ReportFilters = {
  date_field: ReportDateField;
  start_date: string | null;
  end_date: string | null;
  customer_id: number | null;
  status: ReportStatus | null;
  sort: string;
  direction: "asc" | "desc";
};

export type BillingReport = Paginated<Billing> & {
  totals: ReportTotals;
  filters: ReportFilters;
};

export const DATE_FIELDS: ReadonlyArray<{
  value: ReportDateField;
  label: string;
}> = [
  { value: "due_date", label: "Data de vencimento" },
  { value: "issue_date", label: "Data de emissão" },
  { value: "payment_date", label: "Data de pagamento" },
];

export const REPORT_STATUSES: ReadonlyArray<{
  value: ReportStatus;
  label: string;
}> = [
  { value: "pending", label: "Pendente" },
  { value: "overdue", label: "Vencida" },
  { value: "paid", label: "Paga" },
];
