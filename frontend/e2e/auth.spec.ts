import { expect, test } from "@playwright/test";

import { aguardarHidratacao, login, sufixo } from "./helpers";

test.describe("Autenticação", () => {
  test("rota protegida sem sessão volta para o login", async ({ page }) => {
    await page.goto("/relatorio");

    await expect(page).toHaveURL(/\/login/);
    await expect(page.getByRole("button", { name: "Entrar" })).toBeVisible();
  });

  test("senha errada não entra e explica", async ({ page }) => {
    await page.goto("/login");

    const entrar = page.getByRole("button", { name: "Entrar" });
    await aguardarHidratacao(entrar);

    /*
     * E-mail diferente a cada execução, e não o do administrador.
     *
     * O login tem rate limit por credencial — cinco tentativas erradas por
     * minuto. Usar sempre o mesmo e-mail faria a terceira execução seguida
     * desta suíte receber 429, e o teste falharia com a mensagem errada por
     * um motivo que não é o dele. A resposta a e-mail inexistente é a mesma:
     * "Credenciais inválidas", de propósito.
     */
    await page.getByLabel("E-mail").fill(`nao-existe-${sufixo()}@billing.test`);
    await page.getByLabel("Senha").fill("senha-que-nao-e-a-senha");
    await entrar.click();

    await expect(page.getByText(/credenciais/i)).toBeVisible();
    await expect(page).toHaveURL(/\/login/);
  });

  test("login entra na aplicação e o logout devolve ao login", async ({ page }) => {
    await login(page);

    // A casca da aplicação é outro documento, e precisa hidratar antes de o
    // clique valer.
    const sair = page.getByRole("button", { name: "Sair" });
    await aguardarHidratacao(sair);
    await sair.click();

    await expect(page).toHaveURL(/\/login/);

    // A sessão morreu de verdade: a rota protegida não abre mais.
    await page.goto("/clientes");
    await expect(page).toHaveURL(/\/login/);
  });
});
