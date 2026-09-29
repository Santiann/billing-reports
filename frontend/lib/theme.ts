/**
 * The interface theme.
 *
 * Three states and not two: "system" has to exist as a choice of its own,
 * otherwise whoever prefers to follow the operating system has no way back after
 * touching the selector once.
 *
 * Without importing `next/headers`: this module is also read on the client.
 */

export const THEME_COOKIE = "billing_theme";

export type Theme = "system" | "light" | "dark";

export const THEMES: ReadonlyArray<{ value: Theme; label: string }> = [
  { value: "system", label: "Sistema" },
  { value: "light", label: "Claro" },
  { value: "dark", label: "Escuro" },
];

/** One year: an appearance preference does not expire with the session. */
export const THEME_MAX_AGE = 60 * 60 * 24 * 365;

export function isTheme(value: string | undefined): value is Theme {
  return value === "system" || value === "light" || value === "dark";
}
