/**
 * Nome e opções do cookie de sessão.
 *
 * Sem importar `next/headers`: este módulo é lido também pelo middleware, que
 * roda em outro runtime.
 */

export const SESSION_COOKIE = "billing_session";

/** 8 horas — expediente, não sessão eterna. */
export const SESSION_MAX_AGE = 60 * 60 * 8;

export const sessionCookieOptions = {
  httpOnly: true,
  sameSite: "lax",
  secure: process.env.NODE_ENV === "production",
  path: "/",
} as const;
