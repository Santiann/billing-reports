import { expect, test } from "@playwright/test";

import { aguardarHidratacao, criarCliente, criarCobranca, login } from "./helpers";

test.describe("Pagamento e estorno", () => {
  test("registra o pagamento, congela os valores e entra no histórico", async ({ page }) => {
    await login(page);
    const cliente = await criarCliente(page);
    await criarCobranca(page, cliente);

    // Vencida há 30 dias a 2% ao mês: 1.000,00 vira 1.020,00.
    await expect(page.getByText("R$ 1.020,00").first()).toBeVisible();

    // Campos em branco: assume hoje e o valor atualizado.
    const registrar = page.getByRole("button", { name: "Registrar pagamento" });
    await aguardarHidratacao(registrar);
    await registrar.click();

    await expect(
      page.getByText("Pagamento registrado. Os juros foram congelados na data informada."),
    ).toBeVisible();

    /*
     * A asserção é ancorada na SEÇÃO de pagamento.
     *
     * "Valor pago" aparece duas vezes na página — na ficha e no histórico, que
     * registra a mesma mudança —, e o localizador solto casava as duas. O que
     * se quer afirmar aqui é o valor congelado na ficha.
     */
    const fichaDoPagamento = page
      .locator("section")
      .filter({ has: page.getByRole("heading", { name: "Pagamento", exact: true }) });

    await expect(fichaDoPagamento.getByText("R$ 1.020,00")).toBeVisible();

    // E o formulário de pagamento sai de cena.
    await expect(page.getByRole("button", { name: "Registrar pagamento" })).toHaveCount(0);

    await expect(
      page.getByRole("listitem").filter({ hasText: "Pagamento registrado" }).first(),
    ).toBeVisible();
  });

  test("estorna o pagamento e a cobrança volta a pendente", async ({ page }) => {
    await login(page);
    const cliente = await criarCliente(page);
    await criarCobranca(page, cliente);

    const registrar = page.getByRole("button", { name: "Registrar pagamento" });
    await aguardarHidratacao(registrar);
    await registrar.click();

    await expect(
      page.getByRole("heading", { name: "Pagamento", exact: true }),
    ).toBeVisible();

    // Dois passos: o primeiro clique abre a confirmação.
    const estornar = page.getByRole("button", { name: "Estornar pagamento" });
    await aguardarHidratacao(estornar);
    await estornar.click();

    await page.getByRole("button", { name: "Confirmar estorno" }).click();

    await expect(page.getByText(/voltou a pendente/i)).toBeVisible();

    // O formulário de pagamento voltou, e o histórico guarda as duas operações.
    await expect(page.getByRole("button", { name: "Registrar pagamento" })).toBeVisible();
    await expect(
      page.getByRole("listitem").filter({ hasText: "Pagamento estornado" }).first(),
    ).toBeVisible();
    await expect(
      page.getByRole("listitem").filter({ hasText: "Pagamento registrado" }).first(),
    ).toBeVisible();
  });
});
