import { THEMES, type Theme } from "@/lib/theme";

/**
 * Seletor de tema: sistema, claro, escuro.
 *
 * Formulário HTML puro, com três botões de submit e nenhum JavaScript. O POST
 * vai para um Route Handler que grava o cookie e devolve para a página de
 * origem, e é a navegação que faz o layout raiz rodar de novo no servidor e
 * devolver o `<html>` com o `data-theme` certo.
 *
 * A alternativa óbvia — Server Action com `revalidatePath` — foi tentada e não
 * funciona aqui: numa atualização suave o React não reconcilia atributo do
 * elemento `<html>`, então o cookie mudava, o servidor já respondia o tema
 * novo, e a tela continuava no tema antigo até alguém recarregar.
 *
 * Componente de servidor: não há estado de cliente para manter.
 */

const ICONES: Record<Theme, React.ReactNode> = {
  // Monitor.
  system: (
    <>
      <rect x="3" y="4" width="14" height="10" rx="1.5" />
      <path d="M7 17h6M10 14v3" />
    </>
  ),
  // Sol.
  light: (
    <>
      <circle cx="10" cy="10" r="3.5" />
      <path d="M10 2.5v1.5M10 16v1.5M17.5 10H16M4 10H2.5M15.3 4.7l-1 1M5.7 14.3l-1 1M15.3 15.3l-1-1M5.7 5.7l-1-1" />
    </>
  ),
  // Lua.
  dark: (
    <>
      <path d="M16 11.2A6.5 6.5 0 0 1 8.8 4a6.5 6.5 0 1 0 7.2 7.2z" />
    </>
  ),
};

export function ThemeToggle({ atual }: { atual: Theme }) {
  return (
    <form method="post" action="/api/theme" className="flex items-center">
      <fieldset className="flex items-center gap-0.5 rounded-md border border-rule bg-sunken p-0.5">
        <legend className="sr-only">Tema da interface</legend>

        {THEMES.map(({ value, label }) => (
          <button
            key={value}
            type="submit"
            name="theme"
            value={value}
            aria-pressed={atual === value}
            title={label}
            className={
              "rounded-sm p-1.5 transition-colors " +
              (atual === value
                ? "bg-surface text-ink shadow-card"
                : "text-ink-faint hover:text-ink-muted")
            }
          >
            <svg
              viewBox="0 0 20 20"
              width="15"
              height="15"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.4"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              {ICONES[value]}
            </svg>
            <span className="sr-only">{label}</span>
          </button>
        ))}
      </fieldset>
    </form>
  );
}
