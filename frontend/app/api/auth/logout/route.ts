import { NextResponse } from "next/server";

import { apiFetch } from "@/lib/api";
import { SESSION_COOKIE, sessionCookieOptions } from "@/lib/session";
import { getSessionToken } from "@/lib/server-api";

/**
 * Ends the session.
 *
 * Revokes the token in Laravel and deletes the cookie. The cookie is deleted even if
 * the API call fails: leaving the user stuck in a session they asked to end is worse
 * than an orphaned token, which expires on its own.
 */
export async function POST() {
  const token = await getSessionToken();

  if (token) {
    try {
      await apiFetch("/api/auth/logout", { method: "POST", token });
    } catch {
      // No handling: the cookie goes away regardless, just below.
    }
  }

  const response = NextResponse.json({ message: "Sessão encerrada." });

  response.cookies.set(SESSION_COOKIE, "", {
    ...sessionCookieOptions,
    maxAge: 0,
  });

  return response;
}
