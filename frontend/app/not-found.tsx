import Link from "next/link";

/**
 * 404 da aplicação inteira.
 *
 * Atende dois casos que chegam pelo mesmo caminho: URL que não existe, e
 * `notFound()` chamado de dentro de uma página — que é o que as telas de
 * detalhe de cliente e de cobrança fazem quando a API responde 404. Por isso a
 * mensagem fala de página e de registro: quem digitou um id que não existe
 * precisa entender que o problema é o registro, não o endereço.
 *
 * Os dois casos renderizam em contextos diferentes, e é por isso que a altura
 * é `flex-1` e não `min-h-screen`: a URL inexistente para no layout raiz e
 * ocupa a tela toda, mas o `notFound()` vindo do grupo `(app)` renderiza
 * DENTRO do cabeçalho da aplicação. Ali, uma altura de viewport inteira abaixo
 * do cabeçalho vira scroll vertical — visto em 360px antes de virar commit.
 */
export default function NotFound() {
  return (
    <main className="flex flex-1 items-center justify-center bg-slate-50 px-4 py-16">
      <div className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <p className="text-sm font-medium text-slate-400">Erro 404</p>

        <h1 className="mt-1 text-xl font-semibold text-slate-900">
          Página não encontrada
        </h1>

        <p className="mt-2 text-sm text-slate-600">
          O endereço não existe ou o registro que você procurava foi removido.
        </p>

        <div className="mt-6 flex flex-wrap gap-3">
          <Link
            href="/"
            className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700"
          >
            Ir para o início
          </Link>

          <Link
            href="/cobrancas"
            className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100"
          >
            Ver cobranças
          </Link>
        </div>
      </div>
    </main>
  );
}
