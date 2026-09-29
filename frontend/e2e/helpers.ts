import { expect, type Locator, type Page } from "@playwright/test";

/**
 * Unique suffix per call.
 *
 * These tests write to the development database, which is not cleaned between
 * runs — and the customer document is unique. Without a unique suffix the
 * second run of the day would fail on a conflict, which is the worst kind of
 * flakiness: the kind that only shows up the second time.
 */
export function suffix(): string {
  return String(Date.now()).slice(-9);
}

/**
 * Waits for React to take over the element before interacting with it.
 *
 * This is not fussiness: the first run of this suite failed 8 of 10 tests
 * because of it. The login form is controlled (`value` + `onChange`), and before
 * hydration React has not taken over anything — filling it writes a value into
 * the DOM that hydration then discards, and clicking fires the form's NATIVE
 * submit, which reloads the page clean. Playwright's failure screenshot showed
 * exactly that: an empty form with no error message.
 *
 * The signal is the key React DOM hangs on the node once it starts handling its
 * events. It is React internal API, which is why it only appears here, in the
 * tests — never in application code. The alternative was `waitForTimeout`,
 * which trades a race for a bet.
 *
 * The probed element has to belong to a CLIENT component. The shell's theme
 * form, for instance, is server rendered and would never get these keys.
 */
export async function waitForHydration(target: Locator): Promise<void> {
  await expect(async () => {
    const hydrated = await target.evaluate((element) =>
      Object.keys(element).some((key) => key.startsWith("__react")),
    );

    expect(hydrated, "React has not taken over this element yet").toBe(true);
  }).toPass({ timeout: 45_000, intervals: [200, 500, 1_000] });
}

export async function login(
  page: Page,
  email = "admin@billing.test",
  password = "password",
): Promise<void> {
  await page.goto("/login");

  const signIn = page.getByRole("button", { name: "Entrar" });
  await waitForHydration(signIn);

  await page.getByLabel("E-mail").fill(email);
  await page.getByLabel("Senha").fill(password);
  await signIn.click();

  // The middleware sends you to the root, which is the dashboard for a session.
  await expect(page.getByRole("link", { name: "Cobranças" })).toBeVisible();
}

export type CreatedCustomer = { id: string; name: string; document: string };

/** Creates a customer through the UI and returns what the redirect URL says. */
export async function createCustomer(page: Page): Promise<CreatedCustomer> {
  const id = suffix();
  const name = `Cliente E2E ${id}`;
  const document = `${id}00`;

  await page.goto("/clientes/novo");
  await waitForHydration(page.getByRole("button", { name: "Cadastrar" }));

  await page.getByLabel("Nome").fill(name);
  await page.getByLabel("Documento").fill(document);
  await page.getByLabel("E-mail").fill(`e2e-${id}@exemplo.test`);
  await page.getByRole("button", { name: "Cadastrar" }).click();

  /*
   * The action redirects to the LIST, not to the detail page — so the id is not
   * in the URL. It comes from the detail page, reached through the search, which
   * exercises the filter the screen offers as a bonus.
   */
  await expect(page.getByText("Cliente cadastrado com sucesso.")).toBeVisible();

  await page.goto(`/clientes?search=${document}`);
  await page.getByRole("link", { name }).click();
  await expect(page.getByRole("heading", { name })).toBeVisible();

  const found = /\/clientes\/(\d+)/.exec(page.url());
  expect(found, `unexpected URL on the customer detail page: ${page.url()}`).not.toBeNull();

  return { id: found![1], name, document };
}

export type CreatedBilling = { id: string; description: string };

/**
 * Creates a billing 30 days overdue, at 2% a month.
 *
 * Overdue on purpose: it is the case that has interest to compute, and therefore
 * the one that recording a payment has something to freeze.
 */
export async function createBilling(
  page: Page,
  customer: CreatedCustomer,
): Promise<CreatedBilling> {
  const today = new Date();
  const dueDate = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000);
  const issueDate = new Date(today.getTime() - 60 * 24 * 60 * 60 * 1000);
  const iso = (date: Date) => date.toISOString().slice(0, 10);

  const description = `Cobrança E2E ${suffix()}`;

  await page.goto("/cobrancas/nova");

  // The customer picker is a combobox of its own: it searches on the server and
  // picks from a list, rather than a <select> holding the whole base. It is all
  // client state, so it does not open without hydration.
  const picker = page.getByRole("combobox");
  await waitForHydration(picker);
  await picker.click();
  await picker.fill(customer.name);
  await page
    .getByRole("listbox")
    .getByRole("button", { name: new RegExp(customer.document) })
    .click();

  await page.getByLabel("Descrição").fill(description);
  await page.getByLabel("Valor original (R$)").fill("1000.00");
  await page.getByLabel("Taxa de juros mensal").fill("0.02");
  await page.getByLabel("Data de emissão").fill(iso(issueDate));
  await page.getByLabel("Data de vencimento").fill(iso(dueDate));
  await page.getByRole("button", { name: "Cadastrar" }).click();

  // Same as the customer: the action goes back to the list.
  await expect(page.getByText("Cobrança cadastrada com sucesso.")).toBeVisible();

  await page.goto(`/cobrancas?search=${encodeURIComponent(description)}`);
  await page.getByRole("link", { name: description }).click();
  await expect(page.getByRole("heading", { name: description })).toBeVisible();

  const found = /\/cobrancas\/(\d+)/.exec(page.url());
  expect(found, `unexpected URL on the billing detail page: ${page.url()}`).not.toBeNull();

  return { id: found![1], description };
}
