import { chromium } from "@playwright/test";

/**
 * Warms up the routes before the suite.
 *
 * The target of these tests is the DEVELOPMENT server — that is what
 * `docker compose up -d` delivers — and it compiles each route on first visit.
 * That pushed the first hit of every screen into the tens of seconds and made
 * three tests fail on time rather than on behaviour: the failure screenshot
 * showed the "Entrando…" button still disabled.
 *
 * Warming up here fixes the cause instead of hiding the symptom behind larger
 * limits: compilation happens once, outside the tests, and each test goes back
 * to measuring the application rather than the compiler.
 *
 * It needs a session: without the cookie the middleware redirects before the
 * page is compiled, and the warm up would warm nothing.
 */
const ROUTES = [
  "/",
  "/clientes",
  "/clientes/novo",
  "/cobrancas",
  "/cobrancas/nova",
  "/relatorio?status=paid&per_page=5",
];

export default async function warmUp(): Promise<void> {
  const base = process.env.E2E_BASE_URL ?? "http://localhost:3000";
  const browser = await chromium.launch();
  const page = await browser.newPage({ baseURL: base });

  try {
    await page.goto("/login", { waitUntil: "load", timeout: 120_000 });
    await page.getByLabel("E-mail").fill("admin@billing.test");
    await page.getByLabel("Senha").fill("password");
    await page.getByRole("button", { name: "Entrar" }).click();
    await page
      .getByRole("link", { name: "Cobranças" })
      .waitFor({ state: "visible", timeout: 120_000 });

    for (const route of ROUTES) {
      await page.goto(route, { waitUntil: "load", timeout: 120_000 });
    }
  } finally {
    await browser.close();
  }
}
