import type { Metadata } from "next";
import Link from "next/link";

import { InterestCurve } from "@/components/landing/interest-curve";
import { buttonClasses } from "@/components/ui/button";

/**
 * Página pública, servida na raiz para quem não tem sessão.
 *
 * Toda afirmação numérica daqui é medida, e nenhuma é inventada. Não há
 * depoimento de cliente nem logotipo de empresa porque não há cliente nem
 * empresa: a prova que este sistema tem para oferecer é o que ele faz com dois
 * milhões de cobranças, e é isso que está escrito.
 */

export const metadata: Metadata = {
  title: "Gerador de Relatórios — cobranças com juros compostos",
  description:
    "Juros de atraso calculados no banco, não na planilha. O mesmo número na tela, no relatório e no arquivo exportado, sobre dois milhões de cobranças.",
};

const NUMEROS = [
  { valor: "2.000.000", label: "cobranças na base de medição" },
  { valor: "0,24s", label: "no recorte de um mês por cliente" },
  { valor: "0,84s", label: "para o painel inteiro carregar" },
  { valor: "188", label: "testes automatizados" },
];

const RECURSOS = [
  {
    titulo: "Uma fórmula, não quatro",
    corpo:
      "O cálculo de juros existe num lugar só e responde em SQL e em PHP pela mesma regra. É o que permite ordenar o relatório por valor atualizado sem carregar nada em memória — e o que garante que a tela de detalhe e o arquivo exportado nunca discordem sobre a mesma cobrança.",
  },
  {
    titulo: "O banco filtra, ordena e soma",
    corpo:
      "Nenhuma tela carrega o conjunto inteiro para recortar depois. Os totalizadores saem de uma consulta de agregação sobre o filtro aplicado, não da soma da página que está à vista — quem está na página 3 vê o total do relatório, não o total de dez linhas.",
  },
  {
    titulo: "Exportação que aguenta o volume",
    corpo:
      "O CSV é escrito linha a linha enquanto o resultado é percorrido, sem limite de tamanho. O PDF tem teto de mil linhas, e o teto saiu de medição: o renderizador consome 420 MB para mil linhas e estoura 3 GB em cinco mil. Acima do teto a API recusa e aponta o CSV, em vez de morrer no meio.",
  },
];

const PASSOS = [
  {
    numero: "01",
    titulo: "Traga os clientes e as cobranças",
    corpo:
      "Cadastre pela tela ou importe um CSV. A importação analisa o arquivo antes de gravar e devolve, linha a linha, o que não entrou e por quê.",
  },
  {
    numero: "02",
    titulo: "Acompanhe o que vence e o que venceu",
    corpo:
      "O painel mostra o mês corrente e os últimos doze meses. Cobrança vencida acumula juros compostos sobre os dias de atraso, calculados na hora da consulta.",
  },
  {
    numero: "03",
    titulo: "Feche o período e exporte",
    corpo:
      "Recorte por emissão, vencimento ou pagamento, com os totalizadores do conjunto inteiro. O arquivo sai com o mesmo recorte que está na tela.",
  },
];

export default function LandingPage() {
  return (
    <div className="flex min-h-screen flex-col">
      <header className="border-b border-rule">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-5 py-4">
          <span className="font-display text-xl leading-none text-ink">
            Gerador de Relatórios
          </span>

          <Link href="/login" className={buttonClasses({ size: "sm" })}>
            Entrar no sistema
          </Link>
        </div>
      </header>

      <main className="flex-1">
        {/* --- acima da dobra ------------------------------------------- */}
        <section className="mx-auto max-w-5xl px-5 py-16 sm:py-24">
          <div className="grid items-center gap-12 lg:grid-cols-[1.1fr_1fr]">
            <div>
              <p className="font-mono text-xs uppercase tracking-widest text-ink-faint">
                Faturamento e cobrança
              </p>

              <h1 className="mt-4 font-display text-4xl leading-[1.1] text-ink sm:text-5xl">
                Saiba quanto vale hoje cada cobrança vencida
              </h1>

              <p className="mt-5 max-w-xl text-lg text-ink-muted">
                Juros compostos calculados no banco, não na planilha. O mesmo
                número na tela, no relatório e no arquivo exportado — sobre dois
                milhões de cobranças.
              </p>

              <div className="mt-8 flex flex-wrap items-center gap-3">
                <Link
                  href="/login"
                  className={`${buttonClasses()} w-full justify-center sm:w-auto`}
                >
                  Entrar no sistema
                </Link>

                <a
                  href="http://localhost:8000"
                  className="text-sm text-accent hover:underline"
                >
                  ou leia a documentação da API
                </a>
              </div>
            </div>

            {/*
              A figura mostra o RESULTADO, não a interface: o que uma cobrança
              de mil reais vira quando atrasa. Uma captura de tela do sistema
              seria menos legível e diria menos.
            */}
            <InterestCurve />
          </div>
        </section>

        {/* --- prova ---------------------------------------------------- */}
        <section className="border-y border-rule bg-surface">
          <dl className="mx-auto grid max-w-5xl gap-px bg-rule px-0 sm:grid-cols-2 lg:grid-cols-4">
            {NUMEROS.map((numero) => (
              <div key={numero.label} className="bg-surface px-5 py-6">
                <dt className="sr-only">{numero.label}</dt>
                <dd>
                  <span className="block text-3xl font-semibold text-ink">
                    {numero.valor}
                  </span>
                  <span className="mt-1 block text-sm text-ink-muted">
                    {numero.label}
                  </span>
                </dd>
              </div>
            ))}
          </dl>
        </section>

        {/* --- o problema ----------------------------------------------- */}
        <section className="mx-auto max-w-3xl px-5 py-16 sm:py-20">
          <h2 className="font-display text-3xl leading-tight text-ink">
            A conta muda todo dia, e a planilha não sabe disso
          </h2>

          <div className="mt-5 space-y-4 text-ink-muted">
            <p>
              Cobrança vencida não vale o que está escrito nela. Vale o valor
              original mais os juros dos dias de atraso, e esse número é outro
              amanhã. Quando o cálculo mora numa planilha, cada pessoa que abre
              o arquivo chega a um total diferente.
            </p>
            <p>
              Depois vem a parte que não se resolve com mais uma aba: ordenar
              trinta mil cobranças pelo valor atualizado, somar os juros do
              trimestre inteiro, exportar o recorte sem que o computador
              engasgue.
            </p>
          </div>
        </section>

        {/* --- solução -------------------------------------------------- */}
        <section className="border-t border-rule bg-surface">
          <div className="mx-auto max-w-5xl px-5 py-16 sm:py-20">
            <h2 className="font-display text-3xl leading-tight text-ink">
              O cálculo é do sistema, não de quem abre o arquivo
            </h2>

            <div className="mt-10 grid gap-10 sm:grid-cols-3">
              {RECURSOS.map((recurso) => (
                <div key={recurso.titulo}>
                  <h3 className="border-t-2 border-ink pt-3 text-base font-semibold text-ink">
                    {recurso.titulo}
                  </h3>
                  <p className="mt-3 text-sm leading-relaxed text-ink-muted">
                    {recurso.corpo}
                  </p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* --- como funciona -------------------------------------------- */}
        <section className="mx-auto max-w-5xl px-5 py-16 sm:py-20">
          <h2 className="font-display text-3xl leading-tight text-ink">
            Três passos, e o período fecha
          </h2>

          <ol className="mt-10 grid gap-8 sm:grid-cols-3">
            {PASSOS.map((passo) => (
              <li key={passo.numero}>
                <span className="font-mono text-sm text-ink-faint">
                  {passo.numero}
                </span>
                <h3 className="mt-2 text-base font-semibold text-ink">
                  {passo.titulo}
                </h3>
                <p className="mt-2 text-sm leading-relaxed text-ink-muted">
                  {passo.corpo}
                </p>
              </li>
            ))}
          </ol>
        </section>

        {/* --- chamada final -------------------------------------------- */}
        <section className="border-t border-rule bg-surface">
          <div className="mx-auto max-w-3xl px-5 py-16 text-center sm:py-20">
            <h2 className="font-display text-3xl leading-tight text-ink">
              Abra o relatório e veja o total do período
            </h2>
            <p className="mx-auto mt-4 max-w-xl text-ink-muted">
              O acesso de demonstração já está criado — as credenciais estão no
              README do repositório.
            </p>

            <Link
              href="/login"
              className={`${buttonClasses()} mt-8 w-full justify-center sm:w-auto`}
            >
              Entrar no sistema
            </Link>
          </div>
        </section>
      </main>

      <footer className="border-t border-rule">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-5 py-6 text-xs text-ink-muted">
          <span>
            Relatórios de faturamento · PHP 8.3 + Laravel · Next.js · MySQL 8
          </span>
          <a href="http://localhost:8000" className="text-accent hover:underline">
            Documentação da API
          </a>
        </div>
      </footer>
    </div>
  );
}
