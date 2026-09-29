/**
 * The session cookie's name and options.
 *
 * Without importing `next/headers`: this module is also read by the middleware,
 * which runs on another runtime.
 */

export const SESSION_COOKIE = "billing_session";

/** 8 hours — a working day, not an eternal session. */
export const SESSION_MAX_AGE = 60 * 60 * 8;

export const sessionCookieOptions = {
  httpOnly: true,
  sameSite: "lax",
  secure: process.env.NODE_ENV === "production",
  path: "/",
} as const;
