import { Badge } from "@/components/ui/badge";
import type { Billing } from "@/types/billing";

/**
 * O estado da cobrança como etiqueta.
 *
 * Existe como componente porque a regra aparece em três telas — listagem,
 * detalhe e relatório — e ela tem um detalhe fácil de errar: "vencida" NÃO é
 * um dos dois status gravados. É condição derivada, pendente com vencimento no
 * passado, e o backend a entrega pronta em `is_overdue`. Repetir o ternário em
 * cada tela é como as três acabam discordando.
 */
export function BillingStatusBadge({
  billing,
}: {
  billing: Pick<Billing, "is_overdue" | "status" | "status_label">;
}) {
  if (billing.is_overdue) {
    return <Badge tone="overdue">Vencida</Badge>;
  }

  return (
    <Badge tone={billing.status === "paid" ? "paid" : "pending"}>
      {billing.status_label}
    </Badge>
  );
}
