/*
 * One code per operation, not per verb.
 *
 * `criado` served both the customer and the billing, and both listings render this
 * same component — so creating a BILLING displayed "Cliente cadastrado com
 * sucesso". The end-to-end test surfaced it, while looking for the confirmation on
 * screen. `editado` still serves both because its message names no entity.
 */
const MESSAGES: Record<string, string> = {
  "cliente-criado": "Cliente cadastrado com sucesso.",
  "cobranca-criada": "Cobrança cadastrada com sucesso.",
  editado: "Alterações salvas com sucesso.",
  pago: "Pagamento registrado. Os juros foram congelados na data informada.",
  estornado:
    "Pagamento estornado. A cobrança voltou a pendente e os juros voltaram a correr desde o vencimento.",
};

/**
 * The confirmation of a successful operation.
 *
 * It arrives as a URL parameter rather than client state: that way it survives the
 * redirect the Server Action performs after saving, which is exactly the moment
 * the user needs the confirmation.
 */
export function Feedback({ code }: { code?: string }) {
  const message = code ? MESSAGES[code] : undefined;

  if (!message) {
    return null;
  }

  return (
    <p
      role="status"
      className="mb-4 rounded-md border border-paid/30 bg-paid-soft px-4 py-3 text-sm text-paid"
    >
      {message}
    </p>
  );
}
