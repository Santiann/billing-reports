export type UserRole = "admin" | "viewer";

export type User = {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  /** Rótulo em português, vindo do enum do backend. */
  role_label: string;
  /**
   * Se o perfil pode criar, editar, importar e registrar pagamento.
   *
   * A tela usa isto para esconder o que não adianta oferecer. É conveniência,
   * não barreira — quem manda no acesso é o backend, que responde 403 para a
   * mesma operação mesmo sem tela nenhuma no caminho.
   */
  can_write: boolean;
};

/** Resposta do Laravel em POST /api/auth/login. */
export type LoginResponse = {
  token: string;
  user: User;
};

/** Resposta do Route Handler do Next: o token NÃO volta para o browser. */
export type SessionResponse = {
  user: User;
};
