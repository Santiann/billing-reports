import { fetchAsUser } from "@/lib/server-api";
import type { Customer } from "@/types/customer";
import type { Paginated } from "@/types/pagination";

export type CustomerListParams = {
  search?: string;
  status?: string;
  sort?: string;
  direction?: string;
  page?: string;
  per_page?: string;
};

function toQueryString(params: CustomerListParams): string {
  const query = new URLSearchParams();

  for (const [key, value] of Object.entries(params)) {
    if (value) {
      query.set(key, value);
    }
  }

  return query.toString();
}

/**
 * Filtering, sorting and pagination are passed through to the backend as query
 * parameters. Nothing is narrowed here: Next never receives the whole set.
 */
export async function listCustomers(
  params: CustomerListParams,
): Promise<Paginated<Customer>> {
  const query = toQueryString(params);

  return fetchAsUser<Paginated<Customer>>(
    `/api/customers${query ? `?${query}` : ""}`,
  );
}

export async function getCustomer(id: string): Promise<Customer> {
  const { data } = await fetchAsUser<{ data: Customer }>(
    `/api/customers/${id}`,
  );

  return data;
}
