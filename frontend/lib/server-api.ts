import { cookies } from "next/headers";

import { apiFetch, type ApiFetchOptions } from "@/lib/api";
import { SESSION_COOKIE } from "@/lib/session";

/**
 * Só para código de servidor: importar `next/headers` em componente de client
 * quebra o build, o que aqui funciona como proteção.
 */
export async function getSessionToken(): Promise<string | null> {
  const store = await cookies();

  return store.get(SESSION_COOKIE)?.value ?? null;
}

/**
 * Chama a API já autenticada. O Server Component lê o cookie httpOnly e manda
 * `Authorization: Bearer` — o browser nunca vê o token.
 */
export async function fetchAsUser<T>(
  path: string,
  options: ApiFetchOptions = {},
): Promise<T> {
  return apiFetch<T>(path, { ...options, token: await getSessionToken() });
}
