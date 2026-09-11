import { NextResponse } from "next/server";

import { SESSION_COOKIE, sessionCookieOptions } from "@/lib/session";

/**
 * Saída para o cookie que sobreviveu ao token.
 *
 * Se o cookie existe mas o Laravel responde 401 — token revogado, expirado ou
 * forjado — o middleware manda para "/", o Server Component recebe 401 e
 * manda para "/login", e o middleware devolve para "/": loop infinito.
 *
 * Este handler quebra o ciclo apagando o cookie antes de redirecionar. Ele
 * está fora do matcher do middleware, então responde mesmo "logado".
 */
export async function GET() {
  // Location relativo de propósito. NextResponse.redirect() exige URL
  // absoluta, e dentro do container `request.url` resolve para o endereço de
  // bind (http://0.0.0.0:3000), não para o host que o browser usou — o
  // redirect apontaria para fora do alcance do navegador atrás de um proxy.
  // O middleware já emite relativo; aqui é o mesmo critério.
  const response = new NextResponse(null, {
    status: 307,
    headers: { Location: "/login?expired=1" },
  });

  response.cookies.set(SESSION_COOKIE, "", {
    ...sessionCookieOptions,
    maxAge: 0,
  });

  return response;
}
