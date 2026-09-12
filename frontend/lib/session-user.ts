import { cache } from "react";

import { fetchAsUser } from "@/lib/server-api";
import type { SessionResponse, User } from "@/types/auth";

/**
 * O usuário da sessão, uma vez por requisição.
 *
 * `cache()` do React deduplica por requisição: o layout autenticado e a página
 * que ele embrulha chamam esta função e o Laravel recebe UMA chamada. Sem isso,
 * toda tela que precisasse saber o perfil somaria um round-trip ao
 * `/api/auth/me` que o layout já tinha feito.
 */
export const getSessionUser = cache(async (): Promise<User> => {
  const { user } = await fetchAsUser<SessionResponse>("/api/auth/me");

  return user;
});
