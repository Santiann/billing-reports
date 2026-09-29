"use client";

import { Button } from "@/components/ui/button";

/**
 * The authenticated area's error boundary: it renders inside the header and preserves
 * the navigation. What happens above it — including a failure of the authenticated
 * layout itself — falls through to the root's `app/error.tsx`.
 *
 * `retry` and not `reset`: only the former redoes the fetch. See the comment in the
 * root file.
 */
export default function AppError({
  error,
  retry,
}: {
  error: Error & { digest?: string };
  retry: () => void;
}) {
  return (
    <div className="rounded-lg border border-overdue/30 bg-overdue-soft p-6">
      <h2 className="font-semibold text-overdue">Algo deu errado</h2>

      <p className="mt-1 text-sm text-overdue">
        {error.message || "Não foi possível carregar esta página."}
      </p>

      <Button variant="secondary" size="sm" onClick={() => retry()} className="mt-4">
        Tentar de novo
      </Button>
    </div>
  );
}
