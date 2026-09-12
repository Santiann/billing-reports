"use client";

import Link from "next/link";

/**
 * Fronteira de erro da raiz.
 *
 * A área autenticada já tem a sua em `(app)/error.tsx`, que renderiza dentro
 * do cabeçalho do app e preserva a navegação. Esta cobre o que está fora dele:
 * o `/login` e qualquer rota que falhe antes de entrar no grupo `(app)`. Sem
 * ela, um erro no login cai na tela padrão do Next — em inglês, sem saída e
 * sem identidade do sistema.
 *
 * O que ela não cobre é erro lançado pelo próprio layout raiz; isso exigiria
 * um `global-error.tsx`, que precisa reconstruir `<html>` e `<body>` por conta
 * própria. O layout raiz daqui não faz nada além de montar a página e carregar
 * a fonte, então o custo desse arquivo não se paga.
 *
 * O prop de recuperação é `retry`, não `reset`. São coisas diferentes nesta
 * versão do Next: `retry()` refaz o fetch e re-renderiza, enquanto `reset()`
 * só limpa o estado de erro e reaproveita o payload que já falhou — o que,
 * para queda de API, reexibe o mesmo erro. Verificado derrubando o nginx com
 * a tela aberta: com `reset`, o botão não saía do lugar.
 */
export default function RootError({
  error,
  retry,
}: {
  error: Error & { digest?: string };
  retry: () => void;
}) {
  return (
    <main className="flex flex-1 items-center justify-center bg-slate-50 px-4 py-16">
      <div className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <h1 className="text-xl font-semibold text-slate-900">
          Algo deu errado
        </h1>

        <p className="mt-2 text-sm text-slate-600">
          {error.message || "Não foi possível carregar esta página."}
        </p>

        {/* Em produção o Next troca a mensagem do servidor por uma genérica e
            guarda o texto real no log, referenciado por este digest. É o que
            liga o que o usuário viu ao que foi registrado. */}
        {error.digest ? (
          <p className="mt-2 font-mono text-xs text-slate-400">
            Referência: {error.digest}
          </p>
        ) : null}

        <div className="mt-6 flex flex-wrap gap-3">
          <button
            type="button"
            onClick={() => retry()}
            className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700"
          >
            Tentar de novo
          </button>

          <Link
            href="/"
            className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100"
          >
            Ir para o início
          </Link>
        </div>
      </div>
    </main>
  );
}
