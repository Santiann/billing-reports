import { LoginForm } from "@/components/login-form";

type LoginPageProps = {
  searchParams: Promise<{ redirect?: string; expired?: string }>;
};

/**
 * Só aceita caminho interno. Sem isso, `?redirect=https://outro.site` faria a
 * própria tela de login virar um open redirect. O "//" também é barrado: é
 * URL protocol-relative, e leva para fora.
 */
function safeRedirect(value: string | undefined): string {
  if (!value || !value.startsWith("/") || value.startsWith("//")) {
    return "/";
  }

  return value;
}

export default async function LoginPage({ searchParams }: LoginPageProps) {
  const params = await searchParams;

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
      <div className="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <header className="mb-6">
          <h1 className="text-xl font-semibold text-slate-900">
            Gerador de Relatórios
          </h1>
          <p className="mt-1 text-sm text-slate-600">
            Entre para acessar cobranças e relatórios.
          </p>
        </header>

        {params.expired ? (
          <p
            role="status"
            className="mb-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800"
          >
            Sua sessão expirou. Entre novamente.
          </p>
        ) : null}

        <LoginForm redirectTo={safeRedirect(params.redirect)} />
      </div>
    </main>
  );
}
