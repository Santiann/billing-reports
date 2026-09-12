import type { ReactNode } from "react";

/**
 * Superfície de conteúdo.
 *
 * Régua em volta e sombra quase nula, de propósito: numa tela que empilha
 * filtro, totalizador e tabela, sombra em tudo vira ruído. A separação vem do
 * fio, como num formulário impresso.
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

/** Cabeçalho com fio embaixo, para o título não encostar no conteúdo. */
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
