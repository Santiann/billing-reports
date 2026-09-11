import { fetchAsUser } from "@/lib/server-api";
import type { Billing } from "@/types/billing";
import type { Paginated } from "@/types/pagination";

export type BillingListParams = {
  customer_id?: string;
  status?: string;
  search?: string;
  sort?: string;
  direction?: string;
  page?: string;
  per_page?: string;
};

function toQueryString(params: Record<string, string | undefined>): string {
  const query = new URLSearchParams();

  for (const [key, value] of Object.entries(params)) {
    if (value) {
      query.set(key, value);
    }
  }

  return query.toString();
}

export async function listBillings(
  params: BillingListParams,
): Promise<Paginated<Billing>> {
  const query = toQueryString(params);

  return fetchAsUser<Paginated<Billing>>(
    `/api/billings${query ? `?${query}` : ""}`,
  );
}

export async function getBilling(id: string): Promise<Billing> {
  const { data } = await fetchAsUser<{ data: Billing }>(`/api/billings/${id}`);

  return data;
}
