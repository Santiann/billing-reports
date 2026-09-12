export default function LoadingDashboard() {
  return (
    <div className="animate-pulse">
      <div className="mb-6 h-9 w-52 rounded bg-rule" />
      <div className="mb-6 h-28 rounded-lg bg-rule" />
      <div className="mb-6 h-72 rounded-lg bg-rule" />
      <div className="h-56 rounded-lg bg-rule" />
      <span className="sr-only">Carregando indicadores…</span>
    </div>
  );
}
