import { redirect } from "next/navigation";

import { LogoutButton } from "@/components/logout-button";
import { ApiError } from "@/lib/api";
import { fetchAsUser } from "@/lib/server-api";
import type { SessionResponse } from "@/types/auth";

/**
 * Server Component: lê o cookie httpOnly e chama a API com Bearer. É o
 * caminho que todas as telas de dado vão seguir.
 */
export default async function HomePage() {
  let user;

  try {
    ({ user } = await fetchAsUser<SessionResponse>("/api/auth/me"));
  } catch (error) {
    // Cookie presente e token inválido. Redirecionar direto para /login daria
    // loop com o middleware — quem apaga o cookie é o handler.
    if (error instanceof ApiError && error.status === 401) {
      redirect("/api/auth/expire");
    }

    throw error;
  }

  return (
    <main className="min-h-screen bg-slate-50">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
          <h1 className="text-lg font-semibold text-slate-900">
            Gerador de Relatórios
          </h1>

          <div className="flex items-center gap-3">
            <span className="text-sm text-slate-600">{user.email}</span>
            <LogoutButton />
          </div>
        </div>
      </header>

      <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6">
        <p className="text-slate-700">
          Sessão ativa como <strong className="font-medium">{user.name}</strong>.
        </p>
        <p className="mt-2 text-sm text-slate-500">
          Clientes, cobranças e o relatório de faturamento entram nas próximas
          etapas.
        </p>
      </div>
    </main>
  );
}
