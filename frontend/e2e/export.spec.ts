import { readFileSync } from "node:fs";

import { expect, test } from "@playwright/test";

import { createCustomer, createBilling, login } from "./helpers";

/**
 * Exporting is the flow with the most moving parts in the application: the
 * browser navigates to a Next Route Handler, which attaches the token from the
 * httpOnly cookie and streams Laravel's response. None of those ends show up in
 * a unit test.
 *
 * The scope is filtered by the customer created here, for two reasons: the
 * unfiltered report scans the whole measurement base, and the PDF has a cap of a
 * thousand rows — with the filter the file has one row and both formats fit.
 */
test.describe("Report export", () => {
  test("exports the scope to CSV and to PDF", async ({ page }, testInfo) => {
    await login(page);
    const customer = await createCustomer(page);
    const billing = await createBilling(page, customer);

    await page.goto(`/relatorio?customer_id=${customer.id}`);

    await expect(page.getByRole("cell", { name: billing.description })).toBeVisible();

    // --- CSV ---
    const [csv] = await Promise.all([
      page.waitForEvent("download"),
      page.getByRole("link", { name: "Exportar CSV" }).click(),
    ]);

    const csvPath = testInfo.outputPath("relatorio.csv");
    await csv.saveAs(csvPath);

    expect(csv.suggestedFilename()).toMatch(/\.csv$/);

    const content = readFileSync(csvPath, "utf8");
    expect(content).toContain(billing.description);
    expect(content).toContain(customer.name);

    // --- PDF ---
    const [pdf] = await Promise.all([
      page.waitForEvent("download"),
      page.getByRole("link", { name: "Exportar PDF" }).click(),
    ]);

    const pdfPath = testInfo.outputPath("relatorio.pdf");
    await pdf.saveAs(pdfPath);

    expect(pdf.suggestedFilename()).toMatch(/\.pdf$/);

    // Format signature: proves a PDF came back, and not an error page.
    expect(readFileSync(pdfPath).subarray(0, 4).toString()).toBe("%PDF");
  });
});
