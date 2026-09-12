import Link from "next/link";

import { buttonClasses } from "@/components/ui/button";
import type { Paginated } from "@/types/pagination";

type PaginationProps = {
  meta: Paginated<unknown>["meta"];
  basePath: string;
  searchParams: Record<string, string | undefined>;
};

/**
 * Os links vêm do número da página, não de `meta.links` do Laravel: aqueles
 * carregam a URL que o backend enxerga (http://backend dentro do Compose) e
 * não servem para o browser.
 */
function buildHref(
  basePath: string,
  searchParams: Record<string, string | undefined>,
  page: number,
): string {
  const query = new URLSearchParams();

  for (const [key, value] of Object.entries(searchParams)) {
    if (value && key !== "page" && key !== "sucesso") {
      query.set(key, value);
    }
  }

  if (page > 1) {
    query.set("page", String(page));
  }

  const queryString = query.toString();

  return queryString ? `${basePath}?${queryString}` : basePath;
}

export function Pagination({ meta, basePath, searchParams }: PaginationProps) {
  if (meta.total === 0) {
    return null;
  }

  const hasPrevious = meta.current_page > 1;
  const hasNext = meta.current_page < meta.last_page;

  const linkClass = buttonClasses({ variant: "secondary", size: "sm" });
  const disabledClass = `${linkClass} pointer-events-none border-rule bg-sunken text-ink-faint`;

  return (
    <nav
      aria-label="Paginação"
      className="flex flex-wrap items-center justify-between gap-3 border-t border-rule px-4 py-3"
    >
      <p className="text-sm text-ink-muted">
        <span className="font-mono tabular-nums text-ink">
          {meta.from}–{meta.to}
        </span>{" "}
        de{" "}
        <span className="font-mono tabular-nums text-ink">
          {meta.total.toLocaleString("pt-BR")}
        </span>{" "}
        · página {meta.current_page} de {meta.last_page.toLocaleString("pt-BR")}
      </p>

      <div className="flex items-center gap-2">
        {hasPrevious ? (
          <Link
            href={buildHref(basePath, searchParams, meta.current_page - 1)}
            className={linkClass}
          >
            Anterior
          </Link>
        ) : (
          <span className={disabledClass}>Anterior</span>
        )}

        {hasNext ? (
          <Link
            href={buildHref(basePath, searchParams, meta.current_page + 1)}
            className={linkClass}
          >
            Próxima
          </Link>
        ) : (
          <span className={disabledClass}>Próxima</span>
        )}
      </div>
    </nav>
  );
}
