import { THEMES, type Theme } from "@/lib/theme";

/**
 * The theme selector: system, light, dark.
 *
 * Plain HTML form, with three submit buttons and no JavaScript. The POST goes to a
 * Route Handler that writes the cookie and sends you back to the originating page,
 * and it is the navigation that makes the root layout run again on the server and
 * return the `<html>` with the right `data-theme`.
 *
 * The obvious alternative — a Server Action with `revalidatePath` — was tried and
 * does not work here: on a soft update React does not reconcile attributes on the
 * `<html>` element, so the cookie changed, the server was already answering with
 * the new theme, and the screen stayed on the old one until someone reloaded.
 *
 * A server component: there is no client state to keep.
 */

const ICONS: Record<Theme, React.ReactNode> = {
  // Monitor.
  system: (
    <>
      <rect x="3" y="4" width="14" height="10" rx="1.5" />
      <path d="M7 17h6M10 14v3" />
    </>
  ),
  // Sun.
  light: (
    <>
      <circle cx="10" cy="10" r="3.5" />
      <path d="M10 2.5v1.5M10 16v1.5M17.5 10H16M4 10H2.5M15.3 4.7l-1 1M5.7 14.3l-1 1M15.3 15.3l-1-1M5.7 5.7l-1-1" />
    </>
  ),
  // Moon.
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
              {ICONS[value]}
            </svg>
            <span className="sr-only">{label}</span>
          </button>
        ))}
      </fieldset>
    </form>
  );
}
