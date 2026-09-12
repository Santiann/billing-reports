const MESSAGES: Record<string, string> = {
  criado: "Cliente cadastrado com sucesso.",
  editado: "Alterações salvas com sucesso.",
  pago: "Pagamento registrado. Os juros foram congelados na data informada.",
};

/**
 * Confirmação de operação bem-sucedida.
 *
 * Vem por parâmetro de URL em vez de estado de cliente: assim sobrevive ao
 * redirect que a Server Action faz depois de salvar, que é justamente o
 * momento em que o usuário precisa da confirmação.
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
