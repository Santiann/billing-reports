import Link from "next/link";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";

import { LogoutButton } from "@/components/logout-button";
import { MainNav } from "@/components/main-nav";
import { ThemeToggle } from "@/components/theme-toggle";
import { ApiError } from "@/lib/api";
import { getSessionUser } from "@/lib/session-user";
import { isTheme, THEME_COOKIE } from "@/lib/theme";

/**
 * The authenticated area's layout.
 *
 * The session is checked here, once, rather than on every page. The middleware only
 * looks at the cookie's presence; this call is what confirms the token is valid.
 */
export default async function AppLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  let user;

  try {
    user = await getSessionUser();
  } catch (error) {
    // Cookie present and token invalid: the handler is what deletes the cookie,
    // otherwise middleware and layout redirect to each other in a loop.
    if (error instanceof ApiError && error.status === 401) {
      redirect("/api/auth/expire");
    }

    throw error;
  }

  const chosen = (await cookies()).get(THEME_COOKIE)?.value;
  const theme = isTheme(chosen) ? chosen : "system";

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
              {/* The role is only stated when it limits: administrator is the
                  normal e não precisa de etiqueta. */}
              {user.can_write ? null : (
                <span className="ml-2 rounded-sm bg-sunken px-1.5 py-0.5 text-ink-faint">
                  {user.role_label}
                </span>
              )}
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
