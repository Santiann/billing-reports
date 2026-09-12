import Link from "next/link";

import { buttonClasses } from "@/components/ui/button";

/**
 * Tela inicial, provisória.
 *
 * Vira dashboard com indicadores e gráficos no commit próprio. Até lá, serve
 * para o que serve: dizer o que o sistema é e levar para onde se trabalha.
 */
export default function HomePage() {
  return (
    <div className="max-w-2xl">
      <h1 className="font-display text-4xl leading-tight text-ink">
        Faturamento, cobranças e juros
      </h1>

      <p className="mt-3 text-ink-muted">
        Cadastro de clientes e cobranças, com relatório por período, juros
        compostos sobre atraso e exportação em CSV e PDF.
      </p>

      <div className="mt-8 flex flex-wrap gap-3">
        <Link href="/relatorio" className={buttonClasses()}>
          Abrir relatório
        </Link>
        <Link
          href="/cobrancas"
          className={buttonClasses({ variant: "secondary" })}
        >
          Ver cobranças
        </Link>
        <Link
          href="/clientes"
          className={buttonClasses({ variant: "secondary" })}
        >
          Ver clientes
        </Link>
      </div>
    </div>
  );
}
