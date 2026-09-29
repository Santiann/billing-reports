"use client";

import Link from "next/link";
import { useSelectedLayoutSegment } from "next/navigation";

/**
 * The main navigation, with the current section marked.
 *
 * A client component for one reason only: knowing which section you are in.
 * `useSelectedLayoutSegment` rather than `usePathname` because what matters is the
 * first segment — standing at `/cobrancas/8321/editar`, whoever reads the header
 * needs to see "Cobranças" marked, and comparing the whole path would not give
 * that without a hand-written `startsWith`.
 */

const SECTIONS = [
  { segment: "clientes", href: "/clientes", label: "Clientes" },
  { segment: "cobrancas", href: "/cobrancas", label: "Cobranças" },
  { segment: "relatorio", href: "/relatorio", label: "Relatório" },
] as const;

export function MainNav({ className = "" }: { className?: string }) {
  const segment = useSelectedLayoutSegment();

  return (
    <nav aria-label="Seções" className={`flex items-center gap-1 ${className}`.trim()}>
      {SECTIONS.map((section) => {
        const isActive = segment === section.segment;

        return (
          <Link
            key={section.href}
            href={section.href}
            aria-current={isActive ? "page" : undefined}
            className={
              "rounded-md px-2.5 py-1.5 text-sm transition-colors " +
              (isActive
                ? "bg-sunken font-medium text-ink"
                : "text-ink-muted hover:bg-sunken hover:text-ink")
            }
          >
            {section.label}
          </Link>
        );
      })}
    </nav>
  );
}
