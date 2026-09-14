import { chromium } from "@playwright/test";

/**
 * Aquece as rotas antes da suíte.
 *
 * O alvo destes testes é o servidor de DESENVOLVIMENTO — é o que
 * `docker compose up -d` entrega —, e ele compila cada rota na primeira
 * visita. Isso empurrava o primeiro acesso de cada tela para dezenas de
 * segundos e fez três testes falharem por tempo, não por comportamento: o
 * retrato de falha mostrava o botão "Entrando…" ainda desabilitado.
 *
 * Aquecer aqui resolve a causa em vez de esconder o sintoma com limites
 * maiores: a compilação acontece uma vez, fora dos testes, e cada teste passa
 * a medir a aplicação em vez do compilador.
 *
 * Precisa de sessão: sem cookie o middleware redireciona antes de a página ser
 * compilada, e o aquecimento não aqueceria nada.
 */
const ROTAS = [
  "/",
  "/clientes",
  "/clientes/novo",
  "/cobrancas",
  "/cobrancas/nova",
  "/relatorio?status=paid&per_page=5",
];

export default async function aquecer(): Promise<void> {
  const base = process.env.E2E_BASE_URL ?? "http://localhost:3000";
  const navegador = await chromium.launch();
  const pagina = await navegador.newPage({ baseURL: base });

  try {
    await pagina.goto("/login", { waitUntil: "load", timeout: 120_000 });
    await pagina.getByLabel("E-mail").fill("admin@billing.test");
    await pagina.getByLabel("Senha").fill("password");
    await pagina.getByRole("button", { name: "Entrar" }).click();
    await pagina
      .getByRole("link", { name: "Cobranças" })
      .waitFor({ state: "visible", timeout: 120_000 });

    for (const rota of ROTAS) {
      await pagina.goto(rota, { waitUntil: "load", timeout: 120_000 });
    }
  } finally {
    await navegador.close();
  }
}
