import { NextResponse } from "next/server";

import { SESSION_COOKIE, sessionCookieOptions } from "@/lib/session";

/**
 * The way out for a cookie that outlived its token.
 *
 * If the cookie exists but Laravel answers 401 — token revoked, expired or forged —
 * the middleware sends you to "/", the Server Component gets a 401 and sends you to
 * "/login", and the middleware sends you back to "/": an infinite loop.
 *
 * This handler breaks the cycle by deleting the cookie before redirecting. It sits
 * outside the middleware's matcher, so it answers even while "logged in".
 */
export async function GET() {
  // A relative Location on purpose. NextResponse.redirect() requires an absolute
  // URL, and inside the container `request.url` resolves to the bind address
  // (http://0.0.0.0:3000), not the host the browser used — the redirect would point
  // outside the browser's reach behind a proxy. The middleware already emits
  // relative ones; this is the same criterion.
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
