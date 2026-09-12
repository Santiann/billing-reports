/**
 * Esqueleto do detalhe da cobrança.
 *
 * Mesma razão do detalhe de cliente: sem ele valeria o `loading.tsx` da
 * listagem de cobranças, que desenha uma tabela. Os blocos abaixo seguem o que
 * a tela realmente mostra — seis campos em duas colunas e, embaixo, o painel
 * de pagamento ou o de valor atualizado, que existe nos dois casos.
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
