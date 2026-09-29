import { cookies } from "next/headers";

import { apiFetch, type ApiFetchOptions } from "@/lib/api";
import { SESSION_COOKIE } from "@/lib/session";

/**
 * Server code only: importing `next/headers` in a client component breaks the
 * build, which here works as a guard.
 */
export async function getSessionToken(): Promise<string | null> {
  const store = await cookies();

  return store.get(SESSION_COOKIE)?.value ?? null;
}

/**
 * Calls the API already authenticated. The Server Component reads the httpOnly
 * cookie and sends `Authorization: Bearer` — the browser never sees the token.
 */
export async function fetchAsUser<T>(
  path: string,
  options: ApiFetchOptions = {},
): Promise<T> {
  return apiFetch<T>(path, { ...options, token: await getSessionToken() });
}
