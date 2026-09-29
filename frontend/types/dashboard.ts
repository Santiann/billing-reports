export type DashboardPeriod = {
  label: string;
  start_date: string;
  end_date: string;
  count: number;
  /** Sum of the original amount of the billings falling due in the month. */
  original_amount: string;
  /** Frozen at payment time, never recomputed. */
  received_amount: string;
  /** The UPDATED amount of what is still unpaid: interest already included. */
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
