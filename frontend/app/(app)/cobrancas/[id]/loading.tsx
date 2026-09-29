/**
 * The billing detail's skeleton.
 *
 * The same reason as the customer detail's: without it the billing listing's
 * `loading.tsx` would apply, and that one draws a table. The blocks below follow what
 * the screen actually shows — six fields in two columns and, below them, either the
 * payment panel or the updated-amount panel, one of which always exists.
 */
export default function LoadingBilling() {
  return (
    <div className="animate-pulse">
      <div className="mb-2 h-4 w-28 rounded bg-rule" />

      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="h-7 w-64 rounded bg-rule" />
          <div className="h-5 w-20 rounded-full bg-rule" />
        </div>
        <div className="h-9 w-20 rounded-md bg-rule" />
      </div>

      <div className="h-48 rounded-lg bg-rule" />
      <div className="mt-6 h-24 rounded-lg bg-rule" />

      <span className="sr-only">Carregando cobrança…</span>
    </div>
  );
}
