import { expect, test } from "@playwright/test";

import { login } from "./helpers";

/**
 * 360px de largura, que é o critério de aceite do enunciado para telas
 * pequenas.
 *
 * O que se afirma é a ausência de rolagem HORIZONTAL. É a falha típica de
 * layout apertado — tabela, filtro ou cartão que não cabe — e a que mais
 * incomoda no telefone, porque some conteúdo para o lado sem aviso.
 */
const semRolagemHorizontal = async (page: import("@playwright/test").Page) => {
  const sobra = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );

  // Um pixel de folga para arredondamento de subpixel.
  expect(sobra, "a página rola para o lado em 360px").toBeLessThanOrEqual(1);
};

test.describe("Navegação em 360px", () => {
  test("as telas públicas cabem na largura", async ({ page }) => {
    for (const rota of ["/apresentacao", "/login"]) {
      await page.goto(rota);
      await semRolagemHorizontal(page);
    }
  });

  test("as telas autenticadas cabem na largura", async ({ page }) => {
    await login(page);

    // O relatório vai filtrado: sem filtro ele agrega a base de medição
    // inteira, e o assunto deste teste é largura, não desempenho.
    for (const rota of ["/", "/clientes", "/cobrancas", "/relatorio?status=paid&per_page=5"]) {
      await page.goto(rota);
      await semRolagemHorizontal(page);
    }
  });
});
