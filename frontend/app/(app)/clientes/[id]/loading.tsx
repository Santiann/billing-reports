/**
 * Esqueleto do detalhe do cliente.
 *
 * Sem este arquivo, a tela de detalhe herdaria o `loading.tsx` de
 * `/clientes` — o esqueleto da LISTA, com filtros e tabela larga, que não se
 * parece com nada do que vai aparecer. O salto de um layout para o outro é
 * pior do que não ter esqueleto.
 */
export default function LoadingCustomer() {
  return (
    <div className="animate-pulse">
      <div className="mb-2 h-4 w-24 rounded bg-slate-200" />

      <div className="mb-6 flex items-center justify-between gap-3">
        <div className="h-7 w-56 rounded bg-slate-200" />
        <div className="h-9 w-20 rounded-md bg-slate-200" />
      </div>

      <div className="h-32 rounded-lg bg-slate-200" />

      <span className="sr-only">Carregando cliente…</span>
    </div>
  );
}
