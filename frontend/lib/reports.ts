import { fetchAsUser } from "@/lib/server-api";
import type { BillingReport } from "@/types/report";

export type ReportParams = Record<string, string | undefined>;

export async function getBillingReport(
  params: ReportParams,
): Promise<BillingReport> {
  const query = new URLSearchParams();

  for (const [key, value] of Object.entries(params)) {
    // `sucesso` é da UI e não pertence à consulta.
    if (value && key !== "sucesso") {
      query.set(key, value);
    }
  }

  const queryString = query.toString();

  return fetchAsUser<BillingReport>(
    `/api/reports/billings${queryString ? `?${queryString}` : ""}`,
  );
}
