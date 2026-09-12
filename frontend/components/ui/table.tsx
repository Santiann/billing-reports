import type { ReactNode, ThHTMLAttributes } from "react";

/**
 * Tabela de dados.
 *
 * O primitivo existe por causa de uma regra que nenhuma tela pode esquecer:
 * **coluna de dinheiro é tabular e alinhada à direita**. Com algarismo de
 * largura variável, R$ 1.111,11 ocupa menos que R$ 8.888,88 e a coluna deixa de
 * se poder comparar de relance — que é a única razão de existir uma coluna de
 * valor.
 *
 * A rolagem horizontal fica dentro do embrulho, não no corpo da página. Em
 * 360px a tabela do relatório não cabe, e a saída certa é ela rolar sozinha em
 * vez de empurrar a tela inteira.
 */

export function Table({
  children,
  label,
}: {
  children: ReactNode;
  /** Descrição para leitor de tela, já que o <caption> não é exibido. */
  label: string;
}) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full border-collapse text-sm">
        <caption className="sr-only">{label}</caption>
        {children}
      </table>
    </div>
  );
}

export function THead({ children }: { children: ReactNode }) {
  return (
    <thead className="bg-sunken">
      <tr>{children}</tr>
    </thead>
  );
}

type ThProps = ThHTMLAttributes<HTMLTableCellElement> & {
  numeric?: boolean;
};

export function TH({ numeric, className = "", children, ...props }: ThProps) {
  return (
    <th
      scope="col"
      className={
        "border-b border-rule px-4 py-2.5 text-xs font-semibold uppercase " +
        "tracking-wide text-ink-muted " +
        (numeric ? "text-right " : "text-left ") +
        className
      }
      {...props}
    >
      {children}
    </th>
  );
}

export function TBody({ children }: { children: ReactNode }) {
  return <tbody>{children}</tbody>;
}

export function TR({ children }: { children: ReactNode }) {
  return (
    <tr className="border-b border-rule last:border-b-0 hover:bg-sunken/60">
      {children}
    </tr>
  );
}

export function TD({
  numeric,
  className = "",
  children,
}: {
  numeric?: boolean;
  className?: string;
  children: ReactNode;
}) {
  return (
    <td
      className={
        "px-4 py-3 align-top " +
        (numeric ? "text-right font-mono tabular-nums " : "") +
        className
      }
    >
      {children}
    </td>
  );
}

/** Estado vazio ocupando a largura toda da tabela. */
export function TEmpty({
  colSpan,
  children,
}: {
  colSpan: number;
  children: ReactNode;
}) {
  return (
    <tr>
      <td
        colSpan={colSpan}
        className="px-4 py-10 text-center text-sm text-ink-muted"
      >
        {children}
      </td>
    </tr>
  );
}
