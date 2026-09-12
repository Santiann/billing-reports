"use client";

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
    <div className="rounded-lg border border-red-200 bg-red-50 p-6">
      <h2 className="font-semibold text-red-900">Algo deu errado</h2>

      <p className="mt-1 text-sm text-red-800">
        {error.message || "Não foi possível carregar esta página."}
      </p>

      <button
        type="button"
        onClick={() => retry()}
        className="mt-4 rounded-md border border-red-300 bg-white px-3 py-1.5 text-sm font-medium text-red-800 transition hover:bg-red-100"
      >
        Tentar de novo
      </button>
    </div>
  );
}
