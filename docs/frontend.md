# Frontend: visual foundation and screens

[← README](../README.md)

- [Visual foundation](#visual-foundation)
- [Public page](#public-page)
- [Error and loading states](#error-and-loading-states)

## Visual foundation

The tokens and the primitives live in
[`app/globals.css`](../frontend/app/globals.css) and
[`components/ui/`](../frontend/components/ui/).

**The direction is a ledger's**: paper and ink, a hairline rule instead of a
shadow, and tabular figures in every money column. That is not taste — it is the
shape the domain already has. Whoever checks a billing reads a column of amounts,
and a column of amounts can only be read aligned: with variable-width digits,
`1.111,11` takes less room than `8.888,88` and the at-a-glance comparison is lost.
That is why the table primitive has a `numeric` column that is monospaced,
tabular and right aligned, rather than leaving the decision to each screen.

### A dark theme without a single `dark:` class

The tokens are **semantic** — `--color-ink`, not `--color-slate-900` — and each
one declares both themes at once:

```css
--color-paper: light-dark(#faf8f4, #121214);
--color-overdue: light-dark(#9d2b1e, #e58a7c);
```

`light-dark()` resolves by the element's `color-scheme`, so switching themes means
switching one property on the `<html>` — and no component has to repeat every
colour with a `dark:` prefix. The default follows the operating system;
`data-theme` with `light` or `dark` overrides it, and both selectors already exist
so that a theme switcher is an attribute rather than a rewrite of the colour
table.

The first version of this file took the common route: it repeated the whole token
block inside a `@media (prefers-color-scheme: dark)` and again inside a
`[data-theme="dark"]`. One value ended up mistyped in the second copy — which is
exactly the defect duplicating a colour table produces, and the argument for not
duplicating it.

### No component library

No shadcn/ui, no Radix, no Headless UI, no Material. The five primitives — button,
field, card, badge and table — come to a little over 300 lines, and what they do
is precisely what a generic library would not: the table knows what a money column
is, the badge knows the domain's three states, and the button handles `disabled`
with a sunken background rather than opacity.

There is nothing here that asks for what those libraries solve well — an accessible
combobox, a dialog with a focus trap, a menu with keyboard navigation. This
project's most complex screen is a table with filters. Bringing in Radix for that
would add a dependency, a style to override and an API layer to learn, in exchange
for nothing native HTML does not already give.

What accessibility there is was written by hand, because that is where it usually
gets lost: `Field` ties `label`, `id`, `aria-invalid` and `role="alert"` together
in one place, and the visible focus uses `:focus-visible` — the ring appears for
whoever navigates by Tab and disappears for whoever clicks.

### The theme switcher is an HTML form

Three states — system, light, dark — and "system" is a choice of its own: without
it, whoever prefers to follow the operating system would have no way back after
touching the switcher once.

The preference goes into a cookie and is **read in the root layout, on the
server**. That is what eliminates the flash: with `localStorage` the page renders
in the wrong theme and switches after hydration, and the common way out is an
inline script in the `<head>` that the bundler cannot see. Reading the cookie on
the server, the `<html>` leaves the very first response with the right
`data-theme`.

**The switcher posts to a Route Handler, not to a Server Action** — and that goes
against this project's general rule that mutations use an Action. The exception is
in the rule itself: Route Handlers are for what the browser needs to *navigate*
to.

The Server Action version was written first and does not work here. The cookie was
written and the server was already answering with the new theme, but the screen
stayed on the old one until someone reloaded: on a soft update React does not
reconcile attributes on the `<html>` element. With `<form method="post">` the
browser really navigates, the root layout runs on the server and the `<html>`
arrives ready — and the switcher works **with no JavaScript at all**.

The handler's first version also fell into a trap this project had already
documented elsewhere: `NextResponse.redirect()` requires an absolute URL, and
inside the container `request.nextUrl.origin` resolves to the bind address
(`http://0.0.0.0:3000`), not the host the browser used. The browser followed to
**another origin**, did not send the session cookie along, and the user landed on
the login on every theme switch. The `Location` is now relative, and the `Referer`
is only accepted if its host matches the `Host` header — which is the host the
browser actually used, and not the one the container thinks it is.

### Typography

| | Family | Role |
|---|---|---|
| Titles | Instrument Serif | gives the product a face |
| Interface | IBM Plex Sans | humanist, good at dense reading |
| Data | IBM Plex Mono | ids, documents and money aligned |

Served by `next/font`, which downloads and self-hosts at build time: no
third-party request at runtime and no layout shift on load.

---

## Public page

The root serves two audiences. **With a session**, `/` is the dashboard, protected
as always. **Without one**, it shows the system's introduction instead of pushing
you to the login — whoever arrives for the first time needs to know what this is
before seeing a password form.

The middleware does that with **`rewrite`, not `redirect`**, and the difference
matters: the address stays `/`. A redirect to `/apresentacao` would change the URL
in the bar and make the browser's back button fight with the login.

The routing table, verified:

| Route | No session | With a session |
|---|---|---|
| `/` | 200, the introduction | 200, the dashboard |
| `/apresentacao` | 200 | 200 — it is a public page |
| `/login` | 200 | 307 to `/` |
| `/clientes`, `/relatorio`, … | 307 to `/login?redirect=…` | 200 |

### Not one of the page's numbers is invented

The copywriting skill is explicit about fabricated statistics, and here the rule is
easy to follow because the proof exists: there is no customer testimonial and no
company logo, because there is no customer and no company. What the page claims is
what was measured — 2,000,000 billings in the base, 0.24s for a one-month scope
for one customer, 0.84s for the dashboard, 293 tests — 283 in the backend and 10
end to end.

The figure above the fold is the same case. It shows a billing of R$ 1,000.00 at
2% a month becoming **R$ 1,061.21** in 90 days, and the curve's seven points were
generated by the system's own `InterestCalculator`, not drawn by eye. Inventing the
curve would be lying about the one thing the page has to prove.

There is no generated imagery either: the landing page skill suggests a hero with
a photo of a satisfied person, and a real interest curve says more about this
product than a stock library would — besides not adding megabytes of binary to the
repository.

---

## Error and loading states

Four App Router convention files, and none of them is decorative.

| File | Covers |
|---|---|
| `app/error.tsx` | Everything that fails outside the `(app)` group: `/login`, and a failure of the authenticated layout itself |
| `app/not-found.tsx` | A non-existent URL and the detail screens' `notFound()` |
| `app/(app)/error.tsx` | The authenticated area, preserving the header |
| `app/(app)/{clientes,cobrancas}/[id]/loading.tsx` | The detail screens' skeletons |

**`retry`, not `reset`.** This is the part you do not discover by reading code. The
error boundary receives both, and they do different things: `retry()` redoes the
fetch and re-renders; `reset()` only clears the error state and reuses the payload
that already failed. For an API outage — which is the real case — `reset()` shows
exactly the same error again, and the "Try again" button becomes an ornament.

That is how the defect surfaced: with the screen open, `docker compose stop
backend`, reload, bring the backend back up and click the button. With `reset`,
nothing happened. With `retry`, the screen comes back. Both error files use
`retry`.

**Height `flex-1`, not `min-h-screen`.** `app/not-found.tsx` renders in two
contexts: on its own in the root layout, when the URL does not exist, and **inside
the application's header**, when a detail screen calls `notFound()`. In the second
case, a full viewport height below the header produces vertical scrolling. Seen at
360px before it became a commit.

**A skeleton of their own on the detail screens.** Without them, the detail would
inherit the listing's `loading.tsx` — the skeleton of a wide table, which looks
nothing like the screen that is about to appear. The jump from one layout to the
other is worse than having no skeleton at all.

What is left out, and why: `global-error.tsx`. It would cover an error thrown by
the root layout, but it has to rebuild `<html>` and `<body>` and does not inherit
the global CSS. This project's root layout assembles the page and loads the fonts,
nothing more — the cost does not pay for itself.
