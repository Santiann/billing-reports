import { expect, test } from "@playwright/test";

import { aguardarHidratacao, criarCliente, login } from "./helpers";

test.describe("Cadastro de clientes", () => {
  test("cadastra um cliente e o encontra na busca", async ({ page }) => {
    await login(page);

    const cliente = await criarCliente(page);

    await expect(page.getByRole("heading", { name: cliente.nome })).toBeVisible();

    // A busca é parâmetro de URL — a tela repassa o que está na query para a
    // API —, então o teste navega por ela.
    await page.goto(`/clientes?search=${cliente.documento}`);

    await expect(page.getByRole("link", { name: cliente.nome })).toBeVisible();
  });

  test("documento repetido é recusado com a mensagem do campo", async ({ page }) => {
    await login(page);

    const cliente = await criarCliente(page);

    await page.goto("/clientes/novo");
    await aguardarHidratacao(page.getByRole("button", { name: "Cadastrar" }));

    await page.getByLabel("Nome").fill("Outro nome");
    await page.getByLabel("Documento").fill(cliente.documento);
    await page.getByLabel("E-mail").fill(`outro-${cliente.documento}@exemplo.test`);
    await page.getByRole("button", { name: "Cadastrar" }).click();

    await expect(page.getByText(/documento/i).first()).toBeVisible();
    await expect(page).toHaveURL(/\/clientes\/novo/);
  });
});
