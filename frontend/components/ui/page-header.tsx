import Link from "next/link";
import type { ReactNode } from "react";

/**
 * Cabeçalho de página: caminho de volta, título e a ação principal.
 *
 * O título é serifado e grande porque é o único ponto da tela onde a identidade
 * do produto aparece — o resto é densidade de dado. A régua embaixo repete o
 * motivo do papel pautado e separa o cabeçalho do conteúdo sem sombra.
 */
export function PageHeader({
  title,
  voltar,
  badge,
  action,
}: {
  title: string;
  /** Link de volta, exibido acima do título. */
  voltar?: { href: string; label: string };
  /** Etiqueta ao lado do título — estado da cobrança, por exemplo. */
  badge?: ReactNode;
  action?: ReactNode;
}) {
  return (
    <header className="mb-6 border-b border-rule pb-4">
      {voltar ? (
        <Link
          href={voltar.href}
          className="mb-2 inline-block text-sm text-ink-muted transition-colors hover:text-ink"
        >
          ← {voltar.label}
        </Link>
      ) : null}

      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="font-display text-3xl leading-none text-ink">{title}</h1>
          {badge}
        </div>

        {action}
      </div>
    </header>
  );
}
