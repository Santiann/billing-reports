import { expect, test } from "@playwright/test";

import { waitForHydration, createCustomer, createBilling, login } from "./helpers";

test.describe("Payment and reversal", () => {
  test("records the payment, freezes the amounts and enters the history", async ({ page }) => {
    await login(page);
    const customer = await createCustomer(page);
    await createBilling(page, customer);

    // 30 days overdue at 2% a month: 1,000.00 becomes 1,020.00.
    await expect(page.getByText("R$ 1.020,00").first()).toBeVisible();

    // Blank fields: assumes today and the updated amount.
    const recordPayment = page.getByRole("button", { name: "Registrar pagamento" });
    await waitForHydration(recordPayment);
    await recordPayment.click();

    await expect(
      page.getByText("Pagamento registrado. Os juros foram congelados na data informada."),
    ).toBeVisible();

    /*
     * The assertion is anchored on the payment SECTION.
     *
     * "Valor pago" appears twice on the page — on the detail panel and in the
     * history, which records the same change — and the loose locator matched
     * both. What we want to assert here is the frozen amount on the panel.
     */
    const paymentPanel = page
      .locator("section")
      .filter({ has: page.getByRole("heading", { name: "Pagamento", exact: true }) });

    await expect(paymentPanel.getByText("R$ 1.020,00")).toBeVisible();

    // And the payment form leaves the stage.
    await expect(page.getByRole("button", { name: "Registrar pagamento" })).toHaveCount(0);

    await expect(
      page.getByRole("listitem").filter({ hasText: "Pagamento registrado" }).first(),
    ).toBeVisible();
  });

  test("reverses the payment and the billing goes back to pending", async ({ page }) => {
    await login(page);
    const customer = await createCustomer(page);
    await createBilling(page, customer);

    const recordPayment = page.getByRole("button", { name: "Registrar pagamento" });
    await waitForHydration(recordPayment);
    await recordPayment.click();

    await expect(
      page.getByRole("heading", { name: "Pagamento", exact: true }),
    ).toBeVisible();

    // Two steps: the first click opens the confirmation.
    const reverse = page.getByRole("button", { name: "Estornar pagamento" });
    await waitForHydration(reverse);
    await reverse.click();

    await page.getByRole("button", { name: "Confirmar estorno" }).click();

    await expect(page.getByText(/voltou a pendente/i)).toBeVisible();

    // The payment form is back, and the history keeps both operations.
    await expect(page.getByRole("button", { name: "Registrar pagamento" })).toBeVisible();
    await expect(
      page.getByRole("listitem").filter({ hasText: "Pagamento estornado" }).first(),
    ).toBeVisible();
    await expect(
      page.getByRole("listitem").filter({ hasText: "Pagamento registrado" }).first(),
    ).toBeVisible();
  });
});
