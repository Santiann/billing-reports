import { expect, test } from "@playwright/test";

import { login } from "./helpers";

/**
 * 360px wide, which is the acceptance criterion in the brief for small screens.
 *
 * What gets asserted is the absence of HORIZONTAL scrolling. It is the typical
 * failure of a cramped layout — a table, filter or card that does not fit — and
 * the one that annoys most on a phone, because content disappears sideways with
 * no warning.
 */
const noHorizontalScroll = async (page: import("@playwright/test").Page) => {
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );

  // One pixel of slack for subpixel rounding.
  expect(overflow, "the page scrolls sideways at 360px").toBeLessThanOrEqual(1);
};

test.describe("Navigation at 360px", () => {
  test("the public screens fit the width", async ({ page }) => {
    for (const route of ["/apresentacao", "/login"]) {
      await page.goto(route);
      await noHorizontalScroll(page);
    }
  });

  test("the authenticated screens fit the width", async ({ page }) => {
    await login(page);

    // The report goes in filtered: unfiltered it aggregates the whole
    // measurement base, and the subject of this test is width, not performance.
    for (const route of ["/", "/clientes", "/cobrancas", "/relatorio?status=paid&per_page=5"]) {
      await page.goto(route);
      await noHorizontalScroll(page);
    }
  });
});
