import { expect, test } from "@playwright/test";

import { waitForHydration, createCustomer, login } from "./helpers";

test.describe("Creating customers", () => {
  test("creates a customer and finds it through the search", async ({ page }) => {
    await login(page);

    const customer = await createCustomer(page);

    await expect(page.getByRole("heading", { name: customer.name })).toBeVisible();

    // The search is a URL parameter — the screen passes what is in the query
    // through to the API — so the test navigates by it.
    await page.goto(`/clientes?search=${customer.document}`);

    await expect(page.getByRole("link", { name: customer.name })).toBeVisible();
  });

  test("a repeated document is refused with the field's message", async ({ page }) => {
    await login(page);

    const customer = await createCustomer(page);

    await page.goto("/clientes/novo");
    await waitForHydration(page.getByRole("button", { name: "Cadastrar" }));

    await page.getByLabel("Nome").fill("Outro nome");
    await page.getByLabel("Documento").fill(customer.document);
    await page.getByLabel("E-mail").fill(`outro-${customer.document}@exemplo.test`);
    await page.getByRole("button", { name: "Cadastrar" }).click();

    await expect(page.getByText(/documento/i).first()).toBeVisible();
    await expect(page).toHaveURL(/\/clientes\/novo/);
  });
});
