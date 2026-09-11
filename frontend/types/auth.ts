export type User = {
  id: number;
  name: string;
  email: string;
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
