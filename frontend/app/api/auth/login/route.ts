import { NextResponse } from "next/server";

import { ApiError, apiFetch } from "@/lib/api";
import {
  SESSION_COOKIE,
  SESSION_MAX_AGE,
  sessionCookieOptions,
} from "@/lib/session";
import type { LoginResponse } from "@/types/auth";

/**
 * Troca credenciais por sessão.
 *
 * O browser posta aqui, não no Laravel. Este handler chama a API, recebe o
 * token Sanctum, grava num cookie httpOnly e devolve apenas o usuário. O token
 * nunca chega ao JavaScript do cliente — é isso que impede que um XSS o roube,
 * e é por isso que não existe token em localStorage neste projeto.
 */
export async function POST(request: Request) {
  let credentials: { email?: unknown; password?: unknown };

  try {
    credentials = await request.json();
  } catch {
    return NextResponse.json(
      { message: "Corpo da requisição inválido." },
      { status: 400 },
    );
  }

  try {
    const { token, user } = await apiFetch<LoginResponse>("/api/auth/login", {
      method: "POST",
      body: {
        email: credentials.email,
        password: credentials.password,
      },
    });

    const response = NextResponse.json({ user });

    response.cookies.set(SESSION_COOKIE, token, {
      ...sessionCookieOptions,
      maxAge: SESSION_MAX_AGE,
    });

    return response;
  } catch (error) {
    // 401 e 422 do Laravel são respostas legítimas e passam adiante com o
    // corpo original, para o formulário mostrar a mensagem certa.
    if (error instanceof ApiError) {
      return NextResponse.json(error.payload ?? { message: error.message }, {
        status: error.status,
      });
    }

    // Aqui a API não respondeu. Não é erro de credencial.
    return NextResponse.json(
      { message: "Não foi possível falar com a API." },
      { status: 502 },
    );
  }
}
