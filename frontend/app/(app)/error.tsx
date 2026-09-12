"use client";

import { Button } from "@/components/ui/button";

/**
 * Fronteira de erro da área autenticada: renderiza dentro do cabeçalho e
 * preserva a navegação. O que acontece acima dela — inclusive a falha do
 * próprio layout autenticado — cai no `app/error.tsx` da raiz.
 *
 * `retry` e não `reset`: só o primeiro refaz o fetch. Ver o comentário do
 * arquivo da raiz.
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
