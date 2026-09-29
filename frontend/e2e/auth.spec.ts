import { expect, test } from "@playwright/test";

import { waitForHydration, login, suffix } from "./helpers";

test.describe("Authentication", () => {
  test("a protected route without a session goes back to the login", async ({ page }) => {
    await page.goto("/relatorio");

    await expect(page).toHaveURL(/\/login/);
    await expect(page.getByRole("button", { name: "Entrar" })).toBeVisible();
  });

  test("a wrong password does not get in and says why", async ({ page }) => {
    await page.goto("/login");

    const signIn = page.getByRole("button", { name: "Entrar" });
    await waitForHydration(signIn);

    /*
     * A different email on every run, and not the administrator's.
     *
     * Login is rate limited per credential — five wrong attempts a minute.
     * Always using the same email would make the third consecutive run of this
     * suite get a 429, and the test would fail with the wrong message for a
     * reason that is not its own. The response to an unknown email is the same:
     * "Credenciais inválidas", on purpose.
     */
    await page.getByLabel("E-mail").fill(`nao-existe-${suffix()}@billing.test`);
    await page.getByLabel("Senha").fill("senha-que-nao-e-a-senha");
    await signIn.click();

    await expect(page.getByText(/credenciais/i)).toBeVisible();
    await expect(page).toHaveURL(/\/login/);
  });

  test("login gets into the application and logout returns to the login", async ({ page }) => {
    await login(page);

    // The application shell is another document, and has to hydrate before the
    // click counts.
    const signOut = page.getByRole("button", { name: "Sair" });
    await waitForHydration(signOut);
    await signOut.click();

    await expect(page).toHaveURL(/\/login/);

    // The session really died: the protected route no longer opens.
    await page.goto("/clientes");
    await expect(page).toHaveURL(/\/login/);
  });
});
