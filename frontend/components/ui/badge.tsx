import type { ReactNode } from "react";

/**
 * Etiqueta de estado.
 *
 * Os tons saem dos tokens do domínio — vencida, paga, pendente — e não de uma
 * paleta genérica. É o que faz a mesma cor significar a mesma coisa na
 * listagem, no detalhe e no relatório.
 */

export type BadgeTone =
  | "neutral"
  | "overdue"
  | "paid"
  | "pending"
  | "accent"
  // `positive` tem a mesma aparência de `paid` e existe para o código não
  // mentir: um cliente ATIVO não é um cliente pago. Compartilhar a cor é
  // correto — verde significa a mesma coisa nas duas telas —, mas escrever
  // `tone="paid"` num cliente faria o próximo leitor procurar um pagamento
  // que não existe.
  | "positive";

const TONES: Record<BadgeTone, string> = {
  neutral: "bg-sunken text-ink-muted",
  overdue: "bg-overdue-soft text-overdue",
  paid: "bg-paid-soft text-paid",
  pending: "bg-pending-soft text-pending",
  accent: "bg-accent-soft text-accent",
  positive: "bg-paid-soft text-paid",
};

export function Badge({
  tone = "neutral",
  children,
}: {
  tone?: BadgeTone;
  children: ReactNode;
}) {
  return (
    <span
      className={`inline-flex items-center rounded-sm px-2 py-0.5 text-xs font-medium ${TONES[tone]}`}
    >
      {children}
    </span>
  );
}
