import { defineConfig, devices } from "@playwright/test";

/**
 * Os testes de ponta a ponta rodam contra a stack do Compose, não contra um
 * servidor que o Playwright sobe.
 *
 * É deliberado: o que se quer provar é que a aplicação funciona como ela é
 * entregue — Next falando com o nginx pelo nome do serviço, sessão em cookie
 * httpOnly, MySQL de verdade. Um `webServer` do Playwright subiria um Next
 * isolado, sem backend, e os fluxos de cadastro e pagamento não existiriam.
 *
 * `E2E_BASE_URL` muda conforme de onde se roda: `http://frontend:3000` dentro
 * da rede do Compose, `http://localhost:3000` a partir do host.
 */
export default defineConfig({
  testDir: "./e2e",

  // Compila as rotas uma vez, antes da suíte. Ver o arquivo.
  globalSetup: "./e2e/global-setup.ts",

  /*
   * Um worker, e sem paralelismo.
   *
   * Os testes escrevem no MESMO banco de desenvolvimento, e dois deles
   * cadastrando cliente ao mesmo tempo disputariam a unique do documento. O
   * ganho de paralelizar seis testes não paga a intermitência.
   */
  workers: 1,
  fullyParallel: false,

  forbidOnly: Boolean(process.env.CI),
  retries: 0,

  /*
   * Folga alta de propósito.
   *
   * O alvo é o servidor de desenvolvimento, e mesmo com o aquecimento do
   * `globalSetup` uma tela recompila quando um arquivo muda. Limite curto aqui
   * produz falha que não é da aplicação — foi o que aconteceu na terceira
   * execução desta suíte, com o login ainda em voo quando a asserção expirou.
   */
  timeout: 150_000,
  expect: { timeout: 30_000 },

  reporter: [["list"], ["html", { open: "never" }]],

  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost:3000",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
    locale: "pt-BR",
    timezoneId: "America/Sao_Paulo",
  },

  projects: [
    {
      name: "desktop",
      use: { ...devices["Desktop Chrome"] },
      testIgnore: /responsivo\.spec\.ts/,
    },
    {
      /*
       * 360px é o critério de aceite do enunciado para a navegação em telas
       * pequenas. Só o arquivo de responsividade roda aqui: repetir os fluxos
       * de escrita em outra viewport duplicaria o tempo sem provar nada novo.
       */
      name: "mobile-360",
      use: { ...devices["Desktop Chrome"], viewport: { width: 360, height: 740 } },
      testMatch: /responsivo\.spec\.ts/,
    },
  ],
});
