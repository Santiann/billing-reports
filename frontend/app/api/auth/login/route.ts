import { NextResponse } from "next/server";

import { ApiError, apiFetch } from "@/lib/api";
import {
  SESSION_COOKIE,
  SESSION_MAX_AGE,
  sessionCookieOptions,
} from "@/lib/session";
import type { LoginResponse } from "@/types/auth";

/**
 * Trades credentials for a session.
 *
 * The browser posts here, not to Laravel. This handler calls the API, receives the
 * Sanctum token, writes it into an httpOnly cookie and returns only the user. The
 * token never reaches client JavaScript — that is what stops an XSS from stealing it,
 * and it is why there is no token in localStorage in this project.
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
    // Laravel's 401 and 422 are legitimate responses and pass through with the
    // original body, so the form can show the right message.
    if (error instanceof ApiError) {
      return NextResponse.json(error.payload ?? { message: error.message }, {
        status: error.status,
      });
    }

    // Here the API did not answer at all. This is not a credential error.
    return NextResponse.json(
      { message: "Não foi possível falar com a API." },
      { status: 502 },
    );
  }
}
