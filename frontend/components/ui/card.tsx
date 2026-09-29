import type { ReactNode } from "react";

/**
 * A content surface.
 *
 * A rule around it and almost no shadow, on purpose: on a screen that stacks
 * filters, totals and a table, shadow on everything becomes noise. The separation
 * comes from the line, as on a printed form.
 */
export function Card({
  children,
  className = "",
}: {
  children: ReactNode;
  className?: string;
}) {
  return (
    <section
      className={`rounded-lg border border-rule bg-surface shadow-card ${className}`.trim()}
    >
      {children}
    </section>
  );
}

/** A header with a line below it, so the title does not touch the content. */
export function CardHeader({
  title,
  action,
}: {
  title: string;
  action?: ReactNode;
}) {
  return (
    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-rule px-4 py-3">
      <h2 className="text-sm font-semibold text-ink">{title}</h2>
      {action}
    </header>
  );
}

export function CardBody({
  children,
  className = "",
}: {
  children: ReactNode;
  className?: string;
}) {
  return <div className={`p-4 ${className}`.trim()}>{children}</div>;
}
