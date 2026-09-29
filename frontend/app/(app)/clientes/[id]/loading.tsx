/**
 * The customer detail's skeleton.
 *
 * Without this file the detail screen would inherit `/clientes`'s `loading.tsx` — the
 * LIST's skeleton, with filters and a wide table, which looks nothing like what is
 * about to appear. The jump from one layout to the other is worse than having no
 * skeleton at all.
 */
export default function LoadingCustomer() {
  return (
    <div className="animate-pulse">
      <div className="mb-2 h-4 w-24 rounded bg-rule" />

      <div className="mb-6 flex items-center justify-between gap-3">
        <div className="h-7 w-56 rounded bg-rule" />
        <div className="h-9 w-20 rounded-md bg-rule" />
      </div>

      <div className="h-32 rounded-lg bg-rule" />

      <span className="sr-only">Carregando cliente…</span>
    </div>
  );
}
