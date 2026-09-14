import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
    // Saída do Playwright: o relatório em HTML embute um bundle minificado, e
    // sem isto o ESLint o analisa — 3.054 problemas em código que não é nosso.
    "playwright-report/**",
    "test-results/**",
  ]),
]);

export default eslintConfig;
