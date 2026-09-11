/**
 * Os valores monetários chegam da API como string decimal, de propósito.
 * A conversão para número acontece só na formatação, nunca em cálculo.
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

/** Datas vêm como YYYY-MM-DD. Montar com `new Date(iso)` aplicaria fuso e
 *  poderia exibir o dia anterior, então a quebra é manual. */
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
