export default function LoadingReport() {
  return (
    <div className="animate-pulse">
      <div className="mb-6 h-7 w-56 rounded bg-slate-200" />
      <div className="mb-4 h-40 rounded-lg bg-slate-200" />
      <div className="mb-4 h-20 rounded-lg bg-slate-200" />
      <div className="h-96 rounded-lg bg-slate-200" />
      <span className="sr-only">Gerando relatório…</span>
    </div>
  );
}
