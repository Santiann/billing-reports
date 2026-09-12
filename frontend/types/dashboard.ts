export type DashboardPeriod = {
  label: string;
  start_date: string;
  end_date: string;
  count: number;
  /** Soma do valor original das cobranças que vencem no mês. */
  original_amount: string;
  /** Congelado no pagamento, nunca recalculado. */
  received_amount: string;
  /** Valor ATUALIZADO do que ainda não foi pago: já inclui juros. */
  pending_amount: string;
  interest_amount: string;
  overdue_count: number;
};

export type DashboardMonth = {
  /** "2026-09" */
  month: string;
  /** "set/26" */
  label: string;
  count: number;
  original_amount: string;
  received_amount: string;
};

export type Dashboard = {
  period: DashboardPeriod;
  monthly: DashboardMonth[];
};
