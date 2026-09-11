import { NextResponse } from "next/server";

import { apiFetch } from "@/lib/api";
import { SESSION_COOKIE, sessionCookieOptions } from "@/lib/session";
import { getSessionToken } from "@/lib/server-api";

/**
 * Encerra a sessão.
 *
 * Revoga o token no Laravel e apaga o cookie. O cookie é apagado mesmo se a
 * chamada à API falhar: deixar o usuário preso numa sessão que ele pediu para
 * encerrar é pior do que um token órfão, que expira sozinho.
 */
export async function POST() {
  const token = await getSessionToken();

  if (token) {
    try {
      await apiFetch("/api/auth/logout", { method: "POST", token });
    } catch {
      // Sem tratamento: o cookie some de qualquer jeito, logo abaixo.
    }
  }

  const response = NextResponse.json({ message: "Sessão encerrada." });

  response.cookies.set(SESSION_COOKIE, "", {
    ...sessionCookieOptions,
    maxAge: 0,
  });

  return response;
}
