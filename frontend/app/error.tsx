"use client";

import Link from "next/link";

import { Button, buttonClasses } from "@/components/ui/button";

/**
 * The root error boundary.
 *
 * The authenticated area already has its own in `(app)/error.tsx`, which renders
 * inside the app header and preserves the navigation. This one covers what is outside
 * it: `/login` and any route that fails before entering the `(app)` group. Without
 * it, an error on the login lands on Next's default screen — in English, with no way
 * out and none of the system's identity.
 *
 * What it does not cover is an error thrown by the root layout itself; that would
 * require a `global-error.tsx`, which has to rebuild `<html>` and `<body>` on its own.
 * The root layout here does nothing beyond assembling the page and loading the fonts,
 * so that file does not pay for itself.
 *
 * The recovery prop is `retry`, not `reset`. They are different things in this version
 * of Next: `retry()` redoes the fetch and re-renders, while `reset()` only clears the
 * error state and reuses the payload that already failed — which, for an API outage,
 * shows the same error again. Verified by taking nginx down with the screen open: with
 * `reset`, the button went nowhere.
 */
export default function RootError({
  error,
  retry,
}: {
  error: Error & { digest?: string };
  retry: () => void;
}) {
  return (
    <main className="flex flex-1 items-center justify-center px-4 py-16">
      <div className="w-full max-w-md rounded-lg border border-rule bg-surface p-6 shadow-card sm:p-8">
        <h1 className="font-display text-2xl text-ink">
          Algo deu errado
        </h1>

        <p className="mt-2 text-sm text-ink-muted">
          {error.message || "Não foi possível carregar esta página."}
        </p>

        {/* In production Next swaps the server's message for a generic one and
            guarda o texto real no log, referenciado por este digest. É o que
            liga o que o usuário viu ao que foi registrado. */}
        {error.digest ? (
          <p className="mt-2 font-mono text-xs text-ink-faint">
            Referência: {error.digest}
          </p>
        ) : null}

        <div className="mt-6 flex flex-wrap gap-3">
          <Button onClick={() => retry()}>Tentar de novo</Button>

          <Link
            href="/"
            className={buttonClasses({ variant: "secondary" })}
          >
            Ir para o início
          </Link>
        </div>
      </div>
    </main>
  );
}
