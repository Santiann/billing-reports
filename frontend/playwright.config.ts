import { defineConfig, devices } from "@playwright/test";

/**
 * The end-to-end tests run against the Compose stack, not against a server
 * Playwright brings up.
 *
 * That is deliberate: what we want to prove is that the application works as it
 * is delivered — Next talking to nginx by service name, the session in an
 * httpOnly cookie, a real MySQL. A Playwright `webServer` would start an
 * isolated Next with no backend, and the create and payment flows would not
 * exist.
 *
 * `E2E_BASE_URL` changes with where you run from: `http://frontend:3000` inside
 * the Compose network, `http://localhost:3000` from the host.
 */
export default defineConfig({
  testDir: "./e2e",

  // Compiles the routes once, before the suite. See the file.
  globalSetup: "./e2e/global-setup.ts",

  /*
   * One worker, and no parallelism.
   *
   * The tests write to the SAME development database, and two of them creating
   * a customer at once would contend over the document's unique index. The gain
   * from parallelising six tests does not pay for the flakiness.
   */
  workers: 1,
  fullyParallel: false,

  forbidOnly: Boolean(process.env.CI),
  retries: 0,

  /*
   * Generous slack on purpose.
   *
   * The target is the development server, and even with the `globalSetup` warm
   * up a screen recompiles when a file changes. A short limit here produces a
   * failure that is not the application's — which is what happened on the third
   * run of this suite, with the login still in flight when the assertion timed
   * out.
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
      testIgnore: /responsive\.spec\.ts/,
    },
    {
      /*
       * 360px is the acceptance criterion in the brief for navigation on small
       * screens. Only the responsiveness file runs here: repeating the write
       * flows in another viewport would double the time without proving
       * anything new.
       */
      name: "mobile-360",
      use: { ...devices["Desktop Chrome"], viewport: { width: 360, height: 740 } },
      testMatch: /responsive\.spec\.ts/,
    },
  ],
});
