"use client";

import Link from "next/link";
import { useSelectedLayoutSegment } from "next/navigation";

/**
 * Navegação principal, com a seção corrente marcada.
 *
 * Cliente por um motivo só: saber em qual seção se está. `useSelectedLayoutSegment`
 * em vez de `usePathname` porque o que interessa é o primeiro segmento — estando
 * em `/cobrancas/8321/editar`, quem lê o cabeçalho precisa ver "Cobranças"
 * marcado, e comparar o caminho inteiro não daria isso sem um `startsWith`
 * escrito à mão.
 */

const SECOES = [
  { segmento: "clientes", href: "/clientes", label: "Clientes" },
  { segmento: "cobrancas", href: "/cobrancas", label: "Cobranças" },
  { segmento: "relatorio", href: "/relatorio", label: "Relatório" },
] as const;

export function MainNav({ className = "" }: { className?: string }) {
  const segmento = useSelectedLayoutSegment();

  return (
    <nav aria-label="Seções" className={`flex items-center gap-1 ${className}`.trim()}>
      {SECOES.map((secao) => {
        const ativa = segmento === secao.segmento;

        return (
          <Link
            key={secao.href}
            href={secao.href}
            aria-current={ativa ? "page" : undefined}
            className={
              "rounded-md px-2.5 py-1.5 text-sm transition-colors " +
              (ativa
                ? "bg-sunken font-medium text-ink"
                : "text-ink-muted hover:bg-sunken hover:text-ink")
            }
          >
            {secao.label}
          </Link>
        );
      })}
    </nav>
  );
}
