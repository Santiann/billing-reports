import { Card, CardBody, CardHeader } from "@/components/ui/card";
import {
  formatCurrency,
  formatDate,
  formatDateTime,
  formatPercent,
} from "@/lib/format";
import { BILLING_STATUSES } from "@/types/billing";
import type { BillingAuditEntry } from "@/types/billing-audit";

const MONEY = new Set(["original_amount", "paid_amount", "paid_interest_amount"]);
const DATES = new Set(["issue_date", "due_date", "payment_date"]);

/** Running text stays in the text font; mono is for numbers and dates, as on the detail panel. */
const TEXT = new Set(["description"]);

/**
 * The marker's colour is that of the state the billing ENDED UP in: paid after a
 * payment, pending after a reversal. An edit does not change state and stays
 * neutral.
 */
const MARKER: Record<BillingAuditEntry["event"], string> = {
  updated: "bg-ink-faint",
  paid: "bg-paid",
  reversed: "bg-pending",
};

/**
 * Formats per field, with the same functions as the billing's detail panel.
 *
 * The paid amount in the trail and the paid amount on the panel are the same
 * number, and they need to look like the same number.
 */
function formatByField(field: string, value: string | number | null): string {
  if (value === null) {
    return "—";
  }

  if (MONEY.has(field)) {
    return formatCurrency(value);
  }

  if (DATES.has(field)) {
    return formatDate(String(value));
  }

  if (field === "monthly_interest_rate") {
    return formatPercent(value);
  }

  if (field === "status") {
    return BILLING_STATUSES.find((s) => s.value === value)?.label ?? String(value);
  }

  if (field === "customer_id") {
    return `cliente nº ${value}`;
  }

  return String(value);
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
            {entries.map((entry) => (
              <li key={entry.id} className="px-4 py-4">
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                  <p className="flex items-center gap-2 text-sm">
                    {/* A colour marker with a label beside it: colour does not carry
                        a informação sozinha. */}
                    <span
                      aria-hidden
                      className={
                        "inline-block size-2 rounded-full " +
                        MARKER[entry.event]
                      }
                    />
                    <span className="font-semibold text-ink">
                      {entry.event_label}
                    </span>
                    <span className="text-ink-muted">
                      por {entry.user?.name ?? "sistema"}
                    </span>
                  </p>

                  <time
                    dateTime={entry.created_at}
                    className="font-mono text-xs text-ink-faint"
                  >
                    {formatDateTime(entry.created_at)}
                  </time>
                </div>

                <dl className="mt-3 grid gap-x-4 gap-y-1.5 pl-4 text-sm sm:grid-cols-[10rem_1fr]">
                  {entry.changes.map((change) => (
                    <div key={change.field} className="contents">
                      <dt className="text-ink-muted">{change.label}</dt>
                      <dd
                        className={
                          "text-ink " +
                          (TEXT.has(change.field) ? "" : "font-mono tabular-nums")
                        }
                      >
                        {/* Only what existed gets struck through: a struck "—" vanishes. */}
                        <span
                          className={
                            "text-ink-faint " +
                            (change.from === null
                              ? ""
                              : "line-through decoration-rule-strong")
                          }
                        >
                          {formatByField(change.field, change.from)}
                        </span>
                        <span aria-hidden className="px-2 text-ink-faint">
                          →
                        </span>
                        <span className="sr-only">para</span>
                        {formatByField(change.field, change.to)}
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
