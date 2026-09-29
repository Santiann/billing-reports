/**
 * Monetary amounts arrive from the API as decimal strings, on purpose.
 * The conversion to a number happens only at formatting time, never in a
 * calculation.
 */
export function formatCurrency(value: string | number | null): string {
  if (value === null) {
    return "—";
  }

  return Number(value).toLocaleString("pt-BR", {
    style: "currency",
    currency: "BRL",
  });
}

/** Dates arrive as YYYY-MM-DD. Building one with `new Date(iso)` would apply a
 *  timezone and could show the previous day, so the split is manual. */
export function formatDate(value: string | null): string {
  if (!value) {
    return "—";
  }

  const [year, month, day] = value.split("-");

  return `${day}/${month}/${year}`;
}

export function formatPercent(rate: string | number): string {
  return `${(Number(rate) * 100).toLocaleString("pt-BR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}% a.m.`;
}

/*
 * A timestamp with the time of day, for the audit trail.
 *
 * The timezone is fixed because the server is what formats: a Server Component
 * does not know the browser's timezone, and the container runs in UTC. Without
 * the explicit timezone, a payment recorded at 9pm in Brasília would show up on
 * the following day.
 *
 * The formatter is built once: constructing an `Intl.DateTimeFormat` per call
 * costs more than formatting does.
 */
const DATE_TIME = new Intl.DateTimeFormat("pt-BR", {
  dateStyle: "short",
  timeStyle: "short",
  timeZone: "America/Sao_Paulo",
});

export function formatDateTime(iso: string): string {
  return DATE_TIME.format(new Date(iso));
}
