import type { ReactNode, ThHTMLAttributes } from "react";

/**
 * A data table.
 *
 * The primitive exists because of a rule no screen may forget: **a money column is
 * tabular and right aligned**. With variable-width digits, R$ 1.111,11 takes less
 * room than R$ 8.888,88 and the column can no longer be compared at a glance —
 * which is the only reason an amount column exists.
 *
 * Horizontal scrolling lives inside the wrapper, not on the page body. At 360px the
 * report's table does not fit, and the right way out is for it to scroll on its own
 * rather than push the whole screen.
 */

export function Table({
  children,
  label,
}: {
  children: ReactNode;
  /** A description for screen readers, since the <caption> is not displayed. */
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

/** An empty state spanning the table's full width. */
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
