import Link from "next/link";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";

import { LogoutButton } from "@/components/logout-button";
import { MainNav } from "@/components/main-nav";
import { ThemeToggle } from "@/components/theme-toggle";
import { ApiError } from "@/lib/api";
import { fetchAsUser } from "@/lib/server-api";
import { isTheme, THEME_COOKIE } from "@/lib/theme";
import type { SessionResponse } from "@/types/auth";

/**
 * Layout da área autenticada.
 *
 * A sessão é verificada aqui, uma vez, em vez de em cada página. O middleware
 * só olha a presença do cookie; quem confirma que o token vale é esta chamada.
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

  const escolhido = (await cookies()).get(THEME_COOKIE)?.value;
  const theme = isTheme(escolhido) ? escolhido : "system";

  return (
    <div className="flex min-h-screen flex-col">
      {/*
        Cabeçalho grudado no topo: nas telas de listagem a tabela é longa, e
        rolar até o fim sem perder a navegação vale mais do que os 57px.
      */}
      <header className="sticky top-0 z-10 border-b border-rule bg-surface/95 backdrop-blur">
        {/*
          A ordem muda com a largura. Em 360px a marca e os controles dividem a
          primeira linha e a navegação desce inteira para a segunda — duas
          linhas em vez das três que sairiam de um `justify-between` simples,
          e num cabeçalho grudado no topo cada linha custa tela.
        */}
        <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-5 gap-y-2 px-4 py-3 sm:px-6">
          <Link
            href="/"
            className="font-display text-xl leading-none text-ink transition-colors hover:text-ink-muted"
          >
            Gerador de Relatórios
          </Link>

          <MainNav className="order-3 w-full sm:order-2 sm:w-auto" />

          <div className="order-2 ml-auto flex items-center gap-2 sm:order-3">
            <span className="hidden text-xs text-ink-muted lg:inline">
              {user.email}
            </span>
            <ThemeToggle atual={theme} />
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
