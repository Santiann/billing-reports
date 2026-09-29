import Link from "next/link";
import type { ReactNode } from "react";

/**
 * A page header: the way back, the title and the primary action.
 *
 * The title is serif and large because it is the only point on the screen where the
 * product's identity shows — the rest is data density. The rule below it echoes the
 * reason for ruled paper and separates header from content without a shadow.
 */
export function PageHeader({
  title,
  voltar,
  badge,
  action,
}: {
  title: string;
  /** The back link, shown above the title. */
  voltar?: { href: string; label: string };
  /** A label beside the title — the billing's state, for instance. */
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
