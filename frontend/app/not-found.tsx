import Link from "next/link";

import { buttonClasses } from "@/components/ui/button";

/**
 * The whole application's 404.
 *
 * It serves two cases that arrive by the same path: a URL that does not exist, and
 * `notFound()` called from inside a page — which is what the customer and billing
 * detail screens do when the API answers 404. That is why the message talks about both
 * a page and a record: whoever typed an id that does not exist needs to understand
 * that the problem is the record, not the address.
 *
 * The two cases render in different contexts, and that is why the height is `flex-1`
 * and not `min-h-screen`: a non-existent URL stops at the root layout and fills the
 * whole screen, but a `notFound()` coming from the `(app)` group renders INSIDE the
 * application header. There, a full viewport height below the header becomes vertical
 * scrolling — seen at 360px before it became a commit.
 */
export default function NotFound() {
  return (
    <main className="flex flex-1 items-center justify-center px-4 py-16">
      <div className="w-full max-w-md rounded-lg border border-rule bg-surface p-6 shadow-card sm:p-8">
        <p className="font-mono text-xs uppercase tracking-widest text-ink-faint">Erro 404</p>

        <h1 className="mt-2 font-display text-2xl text-ink">
          Página não encontrada
        </h1>

        <p className="mt-2 text-sm text-ink-muted">
          O endereço não existe ou o registro que você procurava foi removido.
        </p>

        <div className="mt-6 flex flex-wrap gap-3">
          <Link
            href="/"
            className={buttonClasses()}
          >
            Ir para o início
          </Link>

          <Link
            href="/cobrancas"
            className={buttonClasses({ variant: "secondary" })}
          >
            Ver cobranças
          </Link>
        </div>
      </div>
    </main>
  );
}
