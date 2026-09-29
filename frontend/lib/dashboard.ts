import { fetchAsUser } from "@/lib/server-api";
import type { Dashboard } from "@/types/dashboard";

/**
 * One call for the whole screen.
 *
 * Both blocks — the month's indicators and the twelve-month series — come
 * together because they are two database queries and one response. Fetching from
 * two endpoints would mean two sequential round trips to build the same screen.
 */
export function getDashboard(): Promise<Dashboard> {
  return fetchAsUser<Dashboard>("/api/dashboard");
}
