/**
 * Uma entrada da trilha de auditoria de uma cobrança.
 *
 * `from` e `to` chegam como o banco guarda: dinheiro em string decimal, data
 * em YYYY-MM-DD, status pelo valor do enum, cliente pelo id. A formatação é da
 * tela, pelo campo — o mesmo número precisa aparecer igual aqui e na ficha.
 */
export type BillingAuditChange = {
  field: string;
  label: string;
  from: string | number | null;
  to: string | number | null;
};

export type BillingAuditEntry = {
  id: number;
  event: "updated" | "paid" | "reversed";
  event_label: string;
  /** Nulo quando a alteração não veio de uma requisição: console, comando. */
  user: { id: number; name: string } | null;
  changes: BillingAuditChange[];
  created_at: string;
};
