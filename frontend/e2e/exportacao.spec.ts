import { readFileSync } from "node:fs";

import { expect, test } from "@playwright/test";

import { criarCliente, criarCobranca, login } from "./helpers";

/**
 * A exportação é o fluxo com mais partes móveis da aplicação: o browser navega
 * para um Route Handler do Next, que anexa o token do cookie httpOnly e faz
 * stream da resposta do Laravel. Nenhuma dessas pontas aparece num teste de
 * unidade.
 *
 * O recorte é filtrado pelo cliente criado aqui, por dois motivos: o relatório
 * sem filtro varre a base de medição inteira, e o PDF tem teto de mil linhas —
 * com o filtro, o arquivo tem uma linha e os dois formatos cabem.
 */
test.describe("Exportação do relatório", () => {
  test("exporta o recorte em CSV e em PDF", async ({ page }, testInfo) => {
    await login(page);
    const cliente = await criarCliente(page);
    const cobranca = await criarCobranca(page, cliente);

    await page.goto(`/relatorio?customer_id=${cliente.id}`);

    await expect(page.getByRole("cell", { name: cobranca.descricao })).toBeVisible();

    // --- CSV ---
    const [csv] = await Promise.all([
      page.waitForEvent("download"),
      page.getByRole("link", { name: "Exportar CSV" }).click(),
    ]);

    const csvPath = testInfo.outputPath("relatorio.csv");
    await csv.saveAs(csvPath);

    expect(csv.suggestedFilename()).toMatch(/\.csv$/);

    const conteudo = readFileSync(csvPath, "utf8");
    expect(conteudo).toContain(cobranca.descricao);
    expect(conteudo).toContain(cliente.nome);

    // --- PDF ---
    const [pdf] = await Promise.all([
      page.waitForEvent("download"),
      page.getByRole("link", { name: "Exportar PDF" }).click(),
    ]);

    const pdfPath = testInfo.outputPath("relatorio.pdf");
    await pdf.saveAs(pdfPath);

    expect(pdf.suggestedFilename()).toMatch(/\.pdf$/);

    // Assinatura do formato: prova que veio PDF, e não uma página de erro.
    expect(readFileSync(pdfPath).subarray(0, 4).toString()).toBe("%PDF");
  });
});
