import { LoginForm } from "@/components/login-form";

type LoginPageProps = {
  searchParams: Promise<{ redirect?: string; expired?: string }>;
};

/**
 * Only accepts an internal path. Without this, `?redirect=https://outro.site` would
 * turn the login screen itself into an open redirect. "//" is barred too: it is a
 * protocol-relative URL, and it leads outside.
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
    <main className="flex flex-1 items-center justify-center px-4 py-16">
      <div className="w-full max-w-sm">
        {/* A rule under the title: it is the reason for ruled paper, and what anchors
            o formulário em vez de deixá-lo flutuando no meio da tela. */}
        <header className="mb-8 border-b border-rule pb-5">
          <h1 className="font-display text-3xl leading-tight text-ink">
            Gerador de Relatórios
          </h1>
          <p className="mt-1 text-sm text-ink-muted">
            Entre para acessar cobranças e relatórios.
          </p>
        </header>

        {params.expired ? (
          <p
            role="status"
            className="mb-5 rounded-md border border-pending/30 bg-pending-soft px-3 py-2 text-sm text-pending"
          >
            Sua sessão expirou. Entre novamente.
          </p>
        ) : null}

        <LoginForm redirectTo={safeRedirect(params.redirect)} />
      </div>
    </main>
  );
}
