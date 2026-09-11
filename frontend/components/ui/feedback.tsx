const MESSAGES: Record<string, string> = {
  criado: "Cliente cadastrado com sucesso.",
  editado: "Alterações salvas com sucesso.",
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
      className="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
    >
      {message}
    </p>
  );
}
