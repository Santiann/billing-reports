import type { SessionResponse } from "@/types/auth";

/**
 * Chamadas do browser aos Route Handlers do próprio Next.
 *
 * Não passam por lib/api.ts de propósito: o destino aqui é a mesma origem, não
 * a API do Laravel. O browser não tem o token e por isso não pode falar com o
 * Laravel diretamente — quem anexa o Bearer é o servidor.
 *
 * Os componentes chamam estas funções em vez de montar fetch na mão.
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
