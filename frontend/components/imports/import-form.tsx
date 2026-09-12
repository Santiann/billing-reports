"use client";

import Link from "next/link";
import { useActionState, useTransition, type FormEvent } from "react";

import { Button, buttonClasses } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { Field, Input } from "@/components/ui/field";
import { Table, TBody, TD, TH, THead, TR } from "@/components/ui/table";
import type { ImportState } from "@/types/import";

type ImportFormProps = {
  action: (state: ImportState, formData: FormData) => Promise<ImportState>;
  /** Colunas do CSV, na ordem, para o cabeçalho de exemplo e a amostra. */
  columns: ReadonlyArray<{ field: string; label: string }>;
  /**
   * Campo que identifica a linha na tabela de erros.
   *
   * Cliente se reconhece pelo nome e cobrança pela descrição. Fixar "name"
   * deixaria metade dos erros da importação de cobranças sem identificação.
   */
  labelField: string;
  exampleCsv: string;
  voltarHref: string;
};

const INITIAL: ImportState = {};

/**
 * Upload com prévia antes de confirmar.
 *
 * O fluxo tem duas etapas no mesmo formulário. "Analisar" manda o arquivo e
 * volta com o que aconteceria; "Confirmar" reenvia o MESMO arquivo — que ainda
 * está no input do browser — e grava. O botão de confirmar só existe depois da
 * prévia, e some de novo se o usuário trocar o arquivo, porque aí a prévia na
 * tela deixou de descrever o que está selecionado.
 */
export function ImportForm({
  action,
  columns,
  labelField,
  exampleCsv,
  voltarHref,
}: ImportFormProps) {
  const [state, formAction, isActionPending] = useActionState(action, INITIAL);
  const [isTransitionPending, startTransition] = useTransition();

  const isPending = isActionPending || isTransitionPending;
  const report = state.report;
  const importado = state.mode === "import";

  /*
   * O envio passa por `onSubmit`, e não pelo `action` do <form>.
   *
   * Não é preferência: um formulário com `action` de função é RESETADO pelo
   * React depois que a action termina, e o campo de arquivo volta a "nenhum
   * arquivo selecionado". Como a confirmação reenvia o mesmo arquivo, a prévia
   * apagava justamente o que o passo seguinte precisava — o botão de importar
   * ficava sem arquivo e não gravava nada. Visto na tela antes de virar commit.
   *
   * Chamando a action dentro de uma transição, o formulário não é tocado e o
   * arquivo continua no input entre os dois passos.
   */
  function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();

    const formulario = evento.currentTarget;
    const dados = new FormData(formulario);
    const botao = (evento.nativeEvent as SubmitEvent).submitter;

    // Qual botão enviou decide se é prévia ou importação.
    dados.set(
      "acao",
      botao instanceof HTMLButtonElement ? botao.value : "preview",
    );

    startTransition(() => formAction(dados));
  }

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardBody className="p-6">
          <form onSubmit={enviar} className="flex flex-col gap-5">
            <Field
              label="Arquivo CSV"
              htmlFor="file"
              hint="Separador ponto e vírgula ou vírgula. A primeira linha é o cabeçalho."
              errors={state.errors?.file}
            >
              <Input
                id="file"
                name="file"
                type="file"
                accept=".csv,text/csv,text/plain"
                required
                aria-invalid={state.errors?.file ? true : undefined}
                className="file:mr-3 file:rounded-sm file:border-0 file:bg-sunken file:px-3 file:py-1 file:text-sm file:text-ink"
              />
            </Field>

            {state.message ? (
              <p
                role="alert"
                className="rounded-md border border-overdue/30 bg-overdue-soft px-3 py-2 text-sm text-overdue"
              >
                {state.message}
              </p>
            ) : null}

            <div className="flex flex-wrap items-center gap-3 border-t border-rule pt-5">
              <Button
                type="submit"
                name="acao"
                value="preview"
                variant={report && !importado ? "secondary" : "primary"}
                disabled={isPending}
              >
                {isPending ? "Lendo…" : "Analisar arquivo"}
              </Button>

              {/* Confirmar só aparece depois de o usuário ver o que vai entrar. */}
              {report && !importado && report.valid_count > 0 ? (
                <Button type="submit" name="acao" value="import" disabled={isPending}>
                  Importar {report.valid_count.toLocaleString("pt-BR")}{" "}
                  {report.valid_count === 1 ? "registro" : "registros"}
                </Button>
              ) : null}

              <Link href={voltarHref} className={buttonClasses({ variant: "ghost" })}>
                {importado ? "Voltar" : "Cancelar"}
              </Link>
            </div>
          </form>
        </CardBody>
      </Card>

      {report ? (
        <>
          <Resumo state={state} />

          {report.errors.length > 0 ? (
            <Card>
              <CardHeader
                title={
                  importado
                    ? "Linhas que não entraram"
                    : "Linhas que não vão entrar"
                }
              />
              <Table label="Erros por linha">
                <THead>
                  <TH numeric>Linha</TH>
                  <TH>Registro</TH>
                  <TH>Motivo</TH>
                </THead>
                <TBody>
                  {report.errors.map((erro) => (
                    <TR key={erro.line}>
                      <TD numeric className="text-overdue">
                        {erro.line}
                      </TD>
                      <TD className="text-ink-muted">
                        {erro.values[labelField] || "—"}
                        {erro.values.document ? (
                          <span className="block font-mono text-xs">
                            {erro.values.document}
                          </span>
                        ) : null}
                      </TD>
                      <TD>
                        <ul className="space-y-0.5">
                          {erro.messages.map((mensagem) => (
                            <li key={mensagem} className="text-overdue">
                              {mensagem}
                            </li>
                          ))}
                        </ul>
                      </TD>
                    </TR>
                  ))}
                </TBody>
              </Table>

              {report.errors_truncated ? (
                <p className="border-t border-rule px-4 py-3 text-xs text-ink-muted">
                  Mostrando as primeiras {report.errors.length} linhas com erro
                  de um total de {report.error_count.toLocaleString("pt-BR")}.
                </p>
              ) : null}
            </Card>
          ) : null}

          {!importado && report.sample.length > 0 ? (
            <Card>
              <CardHeader title="Amostra do que será importado" />
              <Table label="Amostra">
                <THead>
                  {columns.map((coluna) => (
                    <TH key={coluna.field}>{coluna.label}</TH>
                  ))}
                </THead>
                <TBody>
                  {report.sample.map((linha, i) => (
                    <TR key={i}>
                      {columns.map((coluna) => (
                        <TD key={coluna.field} className="text-ink-muted">
                          {linha[coluna.field] ?? "—"}
                        </TD>
                      ))}
                    </TR>
                  ))}
                </TBody>
              </Table>
              <p className="border-t border-rule px-4 py-3 text-xs text-ink-muted">
                Primeiras {report.sample.length} de{" "}
                {report.valid_count.toLocaleString("pt-BR")} linhas válidas.
              </p>
            </Card>
          ) : null}
        </>
      ) : (
        <Card>
          <CardHeader title="Formato esperado" />
          <CardBody>
            <pre className="overflow-x-auto rounded-md border border-rule bg-sunken p-3 font-mono text-xs text-ink-muted">
              {exampleCsv}
            </pre>
          </CardBody>
        </Card>
      )}
    </div>
  );
}

/** O que aconteceu, em números. */
function Resumo({ state }: { state: ImportState }) {
  const report = state.report!;
  const importado = state.mode === "import";

  const cartoes = [
    { label: "Linhas no arquivo", value: report.total_rows, tone: "text-ink" },
    {
      label: importado ? "Importadas" : "Válidas",
      value: importado ? report.imported_count : report.valid_count,
      tone: "text-paid",
    },
    {
      label: importado ? "Não importadas" : "Com erro",
      value: report.error_count,
      tone: report.error_count > 0 ? "text-overdue" : "text-ink-muted",
    },
  ];

  return (
    <section>
      <p
        role="status"
        className={
          "mb-3 rounded-md border px-4 py-3 text-sm " +
          (importado
            ? "border-paid/30 bg-paid-soft text-paid"
            : "border-accent/30 bg-accent-soft text-accent")
        }
      >
        {importado
          ? `Importação concluída: ${report.imported_count.toLocaleString("pt-BR")} de ${report.total_rows.toLocaleString("pt-BR")} linhas entraram.`
          : "Nada foi gravado ainda. Confira abaixo e confirme para importar."}
      </p>

      <dl className="grid gap-px overflow-hidden rounded-lg border border-rule bg-rule sm:grid-cols-3">
        {cartoes.map((cartao) => (
          <div key={cartao.label} className="bg-surface px-4 py-3">
            <dt className="text-xs font-medium uppercase tracking-wide text-ink-muted">
              {cartao.label}
            </dt>
            <dd className={`mt-1 text-2xl font-semibold ${cartao.tone}`}>
              {cartao.value.toLocaleString("pt-BR")}
            </dd>
          </div>
        ))}
      </dl>
    </section>
  );
}
