import Link from "next/link";
import { redirect } from "next/navigation";

import { LogoutButton } from "@/components/logout-button";
import { ApiError } from "@/lib/api";
import { fetchAsUser } from "@/lib/server-api";
import type { SessionResponse } from "@/types/auth";

/**
 * Layout da área autenticada.
 *
 * A sessão é verificada aqui, uma vez, em vez de em cada página. O middleware
 * só olha a presença do cookie; quem confirma que o token vale é esta
 * chamada.
 */
export default async function AppLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  let user;

  try {
    ({ user } = await fetchAsUser<SessionResponse>("/api/auth/me"));
  } catch (error) {
    // Cookie presente e token inválido: quem apaga o cookie é o handler,
    // senão middleware e layout se redirecionam em loop.
    if (error instanceof ApiError && error.status === 401) {
      redirect("/api/auth/expire");
    }

    throw error;
  }

  return (
    <div className="flex min-h-screen flex-col bg-slate-50">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
          <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
            <Link href="/" className="text-base font-semibold text-slate-900">
              Gerador de Relatórios
            </Link>

            <nav className="flex items-center gap-4 text-sm">
              <Link
                href="/clientes"
                className="text-slate-600 transition hover:text-slate-900"
              >
                Clientes
              </Link>
              <Link
                href="/cobrancas"
                className="text-slate-600 transition hover:text-slate-900"
              >
                Cobranças
              </Link>
            </nav>
          </div>

          <div className="flex items-center gap-3">
            <span className="hidden text-sm text-slate-600 sm:inline">
              {user.email}
            </span>
            <LogoutButton />
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6">
        {children}
      </main>
    </div>
  );
}
