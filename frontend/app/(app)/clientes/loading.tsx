export default function LoadingCustomers() {
  return (
    <div className="animate-pulse">
      <div className="mb-6 h-7 w-40 rounded bg-rule" />
      <div className="mb-4 h-20 rounded-lg bg-rule" />
      <div className="h-80 rounded-lg bg-rule" />
      <span className="sr-only">Carregando clientes…</span>
    </div>
  );
}
