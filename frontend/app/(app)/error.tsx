"use client";

export default function AppError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <div className="rounded-lg border border-red-200 bg-red-50 p-6">
      <h2 className="font-semibold text-red-900">Algo deu errado</h2>

      <p className="mt-1 text-sm text-red-800">
        {error.message || "Não foi possível carregar esta página."}
      </p>

      <button
        type="button"
        onClick={reset}
        className="mt-4 rounded-md border border-red-300 bg-white px-3 py-1.5 text-sm font-medium text-red-800 transition hover:bg-red-100"
      >
        Tentar de novo
      </button>
    </div>
  );
}
