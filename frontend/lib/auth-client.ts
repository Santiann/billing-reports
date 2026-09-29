import type { SessionResponse } from "@/types/auth";

/**
 * Browser calls to Next's own Route Handlers.
 *
 * They deliberately do not go through lib/api.ts: the target here is the same
 * origin, not the Laravel API. The browser does not have the token and therefore
 * cannot talk to Laravel directly — the server is what attaches the Bearer.
 *
 * Components call these functions instead of building fetch by hand.
 */

async function readMessage(response: Response, fallback: string) {
  const payload: unknown = await response.json().catch(() => null);

  return (payload as { message?: string } | null)?.message ?? fallback;
}

export async function login(
  email: string,
  password: string,
): Promise<SessionResponse> {
  const response = await fetch("/api/auth/login", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password }),
  });

  if (!response.ok) {
    throw new Error(
      await readMessage(response, "Não foi possível entrar. Tente de novo."),
    );
  }

  return response.json();
}

export async function logout(): Promise<void> {
  const response = await fetch("/api/auth/logout", { method: "POST" });

  if (!response.ok) {
    throw new Error(
      await readMessage(response, "Não foi possível encerrar a sessão."),
    );
  }
}
