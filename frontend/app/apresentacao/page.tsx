import type { Metadata } from "next";
import Link from "next/link";

import { InterestCurve } from "@/components/landing/interest-curve";
import { buttonClasses } from "@/components/ui/button";

/**
 * The public page, served at the root to whoever has no session.
 *
 * Every numeric claim here is measured, and none is invented. There is no customer
 * testimonial and no company logo because there is no customer and no company: the
 * proof this system has to offer is what it does with two million billings, and that
 * is what is written.
 */

export const metadata: Metadata = {
  title: "Gerador de Relatórios — cobranças com juros compostos",
  description:
    "Juros de atraso calculados no banco, não na planilha. O mesmo número na tela, no relatório e no arquivo exportado, sobre dois milhões de cobranças.",
};

const STATS = [
  { value: "2.000.000", label: "cobranças na base de medição" },
  { value: "0,24s", label: "no recorte de um mês por cliente" },
  { value: "0,84s", label: "para o painel inteiro carregar" },
  { value: "293", label: "testes automatizados" },
];

const FEATURES = [
  {
    title: "Uma fórmula, não quatro",
    body:
      "O cálculo de juros existe num lugar só e responde em SQL e em PHP pela mesma regra. É o que permite ordenar o relatório por valor atualizado sem carregar nada em memória — e o que garante que a tela de detalhe e o arquivo exportado nunca discordem sobre a mesma cobrança.",
  },
  {
    title: "O banco filtra, ordena e soma",
    body:
      "Nenhuma tela carrega o conjunto inteiro para recortar depois. Os totalizadores saem de uma consulta de agregação sobre o filtro aplicado, não da soma da página que está à vista — quem está na página 3 vê o total do relatório, não o total de dez linhas.",
  },
  {
    title: "Exportação que aguenta o volume",
    body:
      "O CSV é escrito linha a linha enquanto o resultado é percorrido, sem limite de tamanho. O PDF tem teto de mil linhas, e o teto saiu de medição: o renderizador consome 420 MB para mil linhas e estoura 3 GB em cinco mil. Acima do teto a API recusa e aponta o CSV, em vez de morrer no meio.",
  },
];

const STEPS = [
  {
    number: "01",
    title: "Traga os clientes e as cobranças",
    body:
      "Cadastre pela tela ou importe um CSV. A importação analisa o arquivo antes de gravar e devolve, linha a linha, o que não entrou e por quê.",
  },
  {
    number: "02",
    title: "Acompanhe o que vence e o que venceu",
    body:
      "O painel mostra o mês corrente e os últimos doze meses. Cobrança vencida acumula juros compostos sobre os dias de atraso, calculados na hora da consulta.",
  },
  {
    number: "03",
    title: "Feche o período e exporte",
    body:
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
        {/* --- above the fold ------------------------------------------- */}
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

        {/* --- proof ---------------------------------------------------- */}
        <section className="border-y border-rule bg-surface">
          <dl className="mx-auto grid max-w-5xl gap-px bg-rule px-0 sm:grid-cols-2 lg:grid-cols-4">
            {STATS.map((stat) => (
              <div key={stat.label} className="bg-surface px-5 py-6">
                <dt className="sr-only">{stat.label}</dt>
                <dd>
                  <span className="block text-3xl font-semibold text-ink">
                    {stat.value}
                  </span>
                  <span className="mt-1 block text-sm text-ink-muted">
                    {stat.label}
                  </span>
                </dd>
              </div>
            ))}
          </dl>
        </section>

        {/* --- the problem ---------------------------------------------- */}
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

        {/* --- the solution --------------------------------------------- */}
        <section className="border-t border-rule bg-surface">
          <div className="mx-auto max-w-5xl px-5 py-16 sm:py-20">
            <h2 className="font-display text-3xl leading-tight text-ink">
              O cálculo é do sistema, não de quem abre o arquivo
            </h2>

            <div className="mt-10 grid gap-10 sm:grid-cols-3">
              {FEATURES.map((feature) => (
                <div key={feature.title}>
                  <h3 className="border-t-2 border-ink pt-3 text-base font-semibold text-ink">
                    {feature.title}
                  </h3>
                  <p className="mt-3 text-sm leading-relaxed text-ink-muted">
                    {feature.body}
                  </p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* --- how it works --------------------------------------------- */}
        <section className="mx-auto max-w-5xl px-5 py-16 sm:py-20">
          <h2 className="font-display text-3xl leading-tight text-ink">
            Três passos, e o período fecha
          </h2>

          <ol className="mt-10 grid gap-8 sm:grid-cols-3">
            {STEPS.map((step) => (
              <li key={step.number}>
                <span className="font-mono text-sm text-ink-faint">
                  {step.number}
                </span>
                <h3 className="mt-2 text-base font-semibold text-ink">
                  {step.title}
                </h3>
                <p className="mt-2 text-sm leading-relaxed text-ink-muted">
                  {step.body}
                </p>
              </li>
            ))}
          </ol>
        </section>

        {/* --- closing call --------------------------------------------- */}
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
