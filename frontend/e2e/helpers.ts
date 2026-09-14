import { expect, type Locator, type Page } from "@playwright/test";

/**
 * Sufixo único por chamada.
 *
 * Os testes escrevem no banco de desenvolvimento, que não é limpo entre
 * execuções — e o documento do cliente tem unique. Sem sufixo único, a segunda
 * execução do dia falharia por conflito, que é a pior forma de intermitência:
 * a que só aparece na segunda vez.
 */
export function sufixo(): string {
  return String(Date.now()).slice(-9);
}

/**
 * Espera o React assumir o elemento antes de interagir com ele.
 *
 * Não é zelo: a primeira execução desta suíte falhou em 8 de 10 testes por
 * causa disso. O formulário de login é controlado (`value` + `onChange`), e
 * antes da hidratação o React ainda não assumiu nada — preencher grava no DOM
 * um valor que a hidratação descarta, e clicar dispara o envio NATIVO do
 * formulário, que recarrega a página limpa. O retrato de falha do Playwright
 * mostrava exatamente isso: formulário vazio, sem mensagem de erro.
 *
 * O sinal é a chave que o React DOM pendura no nó quando passa a tratar os
 * eventos dele. É API interna do React, e por isso só aparece aqui, no teste —
 * nunca no código da aplicação. A alternativa era `waitForTimeout`, que troca
 * uma corrida por uma aposta.
 *
 * O elemento sondado precisa pertencer a um componente CLIENTE. O formulário
 * de tema da casca, por exemplo, é renderizado no servidor e nunca receberia
 * essas chaves.
 */
export async function aguardarHidratacao(alvo: Locator): Promise<void> {
  await expect(async () => {
    const hidratado = await alvo.evaluate((elemento) =>
      Object.keys(elemento).some((chave) => chave.startsWith("__react")),
    );

    expect(hidratado, "o React ainda não assumiu este elemento").toBe(true);
  }).toPass({ timeout: 45_000, intervals: [200, 500, 1_000] });
}

export async function login(
  page: Page,
  email = "admin@billing.test",
  senha = "password",
): Promise<void> {
  await page.goto("/login");

  const entrar = page.getByRole("button", { name: "Entrar" });
  await aguardarHidratacao(entrar);

  await page.getByLabel("E-mail").fill(email);
  await page.getByLabel("Senha").fill(senha);
  await entrar.click();

  // O middleware manda para a raiz, que é o dashboard de quem tem sessão.
  await expect(page.getByRole("link", { name: "Cobranças" })).toBeVisible();
}

export type ClienteCriado = { id: string; nome: string; documento: string };

/** Cadastra um cliente pela tela e devolve o que a URL do redirect informa. */
export async function criarCliente(page: Page): Promise<ClienteCriado> {
  const id = sufixo();
  const nome = `Cliente E2E ${id}`;
  const documento = `${id}00`;

  await page.goto("/clientes/novo");
  await aguardarHidratacao(page.getByRole("button", { name: "Cadastrar" }));

  await page.getByLabel("Nome").fill(nome);
  await page.getByLabel("Documento").fill(documento);
  await page.getByLabel("E-mail").fill(`e2e-${id}@exemplo.test`);
  await page.getByRole("button", { name: "Cadastrar" }).click();

  /*
   * A action redireciona para a LISTA, não para a ficha — então o id não está
   * na URL. Ele vem da ficha, alcançada pela busca, que de quebra exercita o
   * filtro que a tela oferece.
   */
  await expect(page.getByText("Cliente cadastrado com sucesso.")).toBeVisible();

  await page.goto(`/clientes?search=${documento}`);
  await page.getByRole("link", { name: nome }).click();
  await expect(page.getByRole("heading", { name: nome })).toBeVisible();

  const encontrado = /\/clientes\/(\d+)/.exec(page.url());
  expect(encontrado, `URL inesperada na ficha do cliente: ${page.url()}`).not.toBeNull();

  return { id: encontrado![1], nome, documento };
}

export type CobrancaCriada = { id: string; descricao: string };

/**
 * Cadastra uma cobrança vencida há 30 dias, a 2% ao mês.
 *
 * Vencida de propósito: é o caso que tem juros para calcular, e portanto o que
 * o registro de pagamento tem para congelar.
 */
export async function criarCobranca(
  page: Page,
  cliente: ClienteCriado,
): Promise<CobrancaCriada> {
  const hoje = new Date();
  const vencimento = new Date(hoje.getTime() - 30 * 24 * 60 * 60 * 1000);
  const emissao = new Date(hoje.getTime() - 60 * 24 * 60 * 60 * 1000);
  const iso = (data: Date) => data.toISOString().slice(0, 10);

  const descricao = `Cobrança E2E ${sufixo()}`;

  await page.goto("/cobrancas/nova");

  // O seletor de cliente é um combobox próprio: busca no servidor e escolhe
  // numa lista, em vez de um <select> com a base inteira. Ele é todo estado de
  // cliente, então sem hidratação não abre.
  const seletor = page.getByRole("combobox");
  await aguardarHidratacao(seletor);
  await seletor.click();
  await seletor.fill(cliente.nome);
  await page
    .getByRole("listbox")
    .getByRole("button", { name: new RegExp(cliente.documento) })
    .click();

  await page.getByLabel("Descrição").fill(descricao);
  await page.getByLabel("Valor original (R$)").fill("1000.00");
  await page.getByLabel("Taxa de juros mensal").fill("0.02");
  await page.getByLabel("Data de emissão").fill(iso(emissao));
  await page.getByLabel("Data de vencimento").fill(iso(vencimento));
  await page.getByRole("button", { name: "Cadastrar" }).click();

  // Mesma coisa da cobrança: a action volta para a lista.
  await expect(page.getByText("Cobrança cadastrada com sucesso.")).toBeVisible();

  await page.goto(`/cobrancas?search=${encodeURIComponent(descricao)}`);
  await page.getByRole("link", { name: descricao }).click();
  await expect(page.getByRole("heading", { name: descricao })).toBeVisible();

  const encontrado = /\/cobrancas\/(\d+)/.exec(page.url());
  expect(encontrado, `URL inesperada na ficha da cobrança: ${page.url()}`).not.toBeNull();

  return { id: encontrado![1], descricao };
}
