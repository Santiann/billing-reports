/*
 * Um código por operação, e não por verbo.
 *
 * `criado` servia a cliente e a cobrança, e as duas listas renderizam este
 * mesmo componente — então cadastrar uma COBRANÇA exibia "Cliente cadastrado
 * com sucesso". Foi o teste de ponta a ponta que mostrou, ao procurar a
 * confirmação na tela. `editado` continua servindo aos dois porque a mensagem
 * dele não nomeia entidade nenhuma.
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
