/**
 * Tema da interface.
 *
 * Três estados e não dois: "sistema" precisa existir como escolha própria,
 * senão quem prefere acompanhar o sistema operacional fica sem como voltar
 * depois de tocar no seletor uma vez.
 *
 * Sem importar `next/headers`: este módulo é lido também no cliente.
 */

export const THEME_COOKIE = "billing_theme";

export type Theme = "system" | "light" | "dark";

export const THEMES: ReadonlyArray<{ value: Theme; label: string }> = [
  { value: "system", label: "Sistema" },
  { value: "light", label: "Claro" },
  { value: "dark", label: "Escuro" },
];

/** Um ano: preferência de aparência não expira com a sessão. */
export const THEME_MAX_AGE = 60 * 60 * 24 * 365;

export function isTheme(value: string | undefined): value is Theme {
  return value === "system" || value === "light" || value === "dark";
}
