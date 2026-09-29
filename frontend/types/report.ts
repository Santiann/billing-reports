import type { Billing } from "@/types/billing";
import type { Paginated } from "@/types/pagination";

export type ReportDateField = "issue_date" | "due_date" | "payment_date";
export type ReportStatus = "pending" | "paid" | "overdue";

/** Totals for the whole filtered set, not for the page on screen. */
export type ReportTotals = {
  count: number;
  original_amount: string;
  interest_amount: string;
  updated_amount: string;
  paid_amount: string;
  pending_amount: string;
};

/** An echo of the applied filters, as the backend understood them. */
export type ReportFilters = {
  date_field: ReportDateField;
  start_date: string | null;
  end_date: string | null;
  customer_id: number | null;
  status: ReportStatus | null;
  sort: string;
  direction: "asc" | "desc";
};

/**
 * The backend reports the PDF cap and whether the current scope fits it, so the
 * screen can warn before the click instead of sending the user into a 422.
 */
export type ReportExportInfo = {
  pdf_max_rows: number;
  pdf_available: boolean;
};

export type BillingReport = Paginated<Billing> & {
  totals: ReportTotals;
  filters: ReportFilters;
  export: ReportExportInfo;
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
