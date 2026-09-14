import { Card, CardBody, CardHeader } from "@/components/ui/card";
import {
  formatCurrency,
  formatDate,
  formatDateTime,
  formatPercent,
} from "@/lib/format";
import { BILLING_STATUSES } from "@/types/billing";
import type { BillingAuditEntry } from "@/types/billing-audit";

const DINHEIRO = new Set(["original_amount", "paid_amount", "paid_interest_amount"]);
const DATAS = new Set(["issue_date", "due_date", "payment_date"]);

/** Texto corrido fica na fonte do texto; mono é para número e data, como na ficha. */
const TEXTO = new Set(["description"]);

/**
 * A cor do marcador é a do estado em que a cobrança FICOU: paga depois do
 * pagamento, pendente depois do estorno. A edição não muda estado e fica
 * neutra.
 */
const MARCADOR: Record<BillingAuditEntry["event"], string> = {
  updated: "bg-ink-faint",
  paid: "bg-paid",
  reversed: "bg-pending",
};

/**
 * Formata pelo campo, com as mesmas funções da ficha da cobrança.
 *
 * O valor pago na trilha e o valor pago na ficha são o mesmo número, e
 * precisam parecer o mesmo número.
 */
function formatar(campo: string, valor: string | number | null): string {
  if (valor === null) {
    return "—";
  }

  if (DINHEIRO.has(campo)) {
    return formatCurrency(valor);
  }

  if (DATAS.has(campo)) {
    return formatDate(String(valor));
  }

  if (campo === "monthly_interest_rate") {
    return formatPercent(valor);
  }

  if (campo === "status") {
    return BILLING_STATUSES.find((s) => s.value === valor)?.label ?? String(valor);
  }

  if (campo === "customer_id") {
    return `cliente nº ${valor}`;
  }

  return String(valor);
}

export function BillingAuditTrail({
  entries,
  total,
}: {
  entries: BillingAuditEntry[];
  total: number;
}) {
  return (
    <Card className="mt-8">
      <CardHeader title="Histórico de alterações" />
      <CardBody className="p-0">
        {entries.length === 0 ? (
          <p className="px-4 py-5 text-sm text-ink-muted">
            Nenhuma alteração registrada. O histórico começa na primeira edição
            ou no pagamento — o cadastro e a importação não entram nele.
          </p>
        ) : (
          <ol className="divide-y divide-rule">
            {entries.map((entrada) => (
              <li key={entrada.id} className="px-4 py-4">
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                  <p className="flex items-center gap-2 text-sm">
                    {/* Marcador de cor com rótulo ao lado: a cor não carrega
                        a informação sozinha. */}
                    <span
                      aria-hidden
                      className={
                        "inline-block size-2 rounded-full " +
                        MARCADOR[entrada.event]
                      }
                    />
                    <span className="font-semibold text-ink">
                      {entrada.event_label}
                    </span>
                    <span className="text-ink-muted">
                      por {entrada.user?.name ?? "sistema"}
                    </span>
                  </p>

                  <time
                    dateTime={entrada.created_at}
                    className="font-mono text-xs text-ink-faint"
                  >
                    {formatDateTime(entrada.created_at)}
                  </time>
                </div>

                <dl className="mt-3 grid gap-x-4 gap-y-1.5 pl-4 text-sm sm:grid-cols-[10rem_1fr]">
                  {entrada.changes.map((mudanca) => (
                    <div key={mudanca.field} className="contents">
                      <dt className="text-ink-muted">{mudanca.label}</dt>
                      <dd
                        className={
                          "text-ink " +
                          (TEXTO.has(mudanca.field) ? "" : "font-mono tabular-nums")
                        }
                      >
                        {/* Só se risca o que existia: um "—" riscado some. */}
                        <span
                          className={
                            "text-ink-faint " +
                            (mudanca.from === null
                              ? ""
                              : "line-through decoration-rule-strong")
                          }
                        >
                          {formatar(mudanca.field, mudanca.from)}
                        </span>
                        <span aria-hidden className="px-2 text-ink-faint">
                          →
                        </span>
                        <span className="sr-only">para</span>
                        {formatar(mudanca.field, mudanca.to)}
                      </dd>
                    </div>
                  ))}
                </dl>
              </li>
            ))}
          </ol>
        )}

        {total > entries.length ? (
          <p className="border-t border-rule px-4 py-3 text-xs text-ink-muted">
            Mostrando as {entries.length} alterações mais recentes de {total}.
          </p>
        ) : null}
      </CardBody>
    </Card>
  );
}
