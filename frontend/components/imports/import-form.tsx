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
  /** The CSV columns, in order, for the example header and the sample. */
  columns: ReadonlyArray<{ field: string; label: string }>;
  /**
   * The field that identifies the row in the error table.
   *
   * A customer is recognised by name and a billing by description. Hard-coding
   * "name" would leave half the billing import's errors unidentified.
   */
  labelField: string;
  exampleCsv: string;
  backHref: string;
};

const INITIAL: ImportState = {};

/**
 * Upload with a preview before confirming.
 *
 * The flow has two steps in the same form. "Analisar" sends the file and comes
 * back with what would happen; "Confirmar" resends the SAME file — still sitting
 * in the browser's input — and writes. The confirm button only exists after the
 * preview, and disappears again if the user swaps the file, because at that point
 * the preview on screen has stopped describing what is selected.
 */
export function ImportForm({
  action,
  columns,
  labelField,
  exampleCsv,
  backHref,
}: ImportFormProps) {
  const [state, formAction, isActionPending] = useActionState(action, INITIAL);
  const [isTransitionPending, startTransition] = useTransition();

  const isPending = isActionPending || isTransitionPending;
  const report = state.report;
  const imported = state.mode === "import";

  /*
   * Submitting goes through `onSubmit`, not through the <form>'s `action`.
   *
   * This is not a preference: a form with a function `action` is RESET by React
   * once the action finishes, and the file input goes back to "no file selected".
   * Since confirming resends the same file, the preview was wiping exactly what
   * the next step needed — the import button ended up with no file and wrote
   * nothing. Seen on screen before it became a commit.
   *
   * Calling the action inside a transition leaves the form untouched and the file
   * stays in the input between the two steps.
   */
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const form = event.currentTarget;
    const data = new FormData(form);
    const button = (event.nativeEvent as SubmitEvent).submitter;

    // Which button submitted decides whether this is a preview or an import.
    data.set(
      "step",
      button instanceof HTMLButtonElement ? button.value : "preview",
    );

    startTransition(() => formAction(data));
  }

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardBody className="p-6">
          <form onSubmit={submit} className="flex flex-col gap-5">
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
                name="step"
                value="preview"
                variant={report && !imported ? "secondary" : "primary"}
                disabled={isPending}
              >
                {isPending ? "Lendo…" : "Analisar arquivo"}
              </Button>

              {/* Confirm only appears after the user has seen what will go in. */}
              {report && !imported && report.valid_count > 0 ? (
                <Button type="submit" name="step" value="import" disabled={isPending}>
                  Importar {report.valid_count.toLocaleString("pt-BR")}{" "}
                  {report.valid_count === 1 ? "registro" : "registros"}
                </Button>
              ) : null}

              <Link href={backHref} className={buttonClasses({ variant: "ghost" })}>
                {imported ? "Voltar" : "Cancelar"}
              </Link>
            </div>
          </form>
        </CardBody>
      </Card>

      {report ? (
        <>
          <Summary state={state} />

          {report.errors.length > 0 ? (
            <Card>
              <CardHeader
                title={
                  imported
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
                  {report.errors.map((errorRow) => (
                    <TR key={errorRow.line}>
                      <TD numeric className="text-overdue">
                        {errorRow.line}
                      </TD>
                      <TD className="text-ink-muted">
                        {errorRow.values[labelField] || "—"}
                        {errorRow.values.document ? (
                          <span className="block font-mono text-xs">
                            {errorRow.values.document}
                          </span>
                        ) : null}
                      </TD>
                      <TD>
                        <ul className="space-y-0.5">
                          {errorRow.messages.map((errorMessage) => (
                            <li key={errorMessage} className="text-overdue">
                              {errorMessage}
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

          {!imported && report.sample.length > 0 ? (
            <Card>
              <CardHeader title="Amostra do que será importado" />
              <Table label="Amostra">
                <THead>
                  {columns.map((column) => (
                    <TH key={column.field}>{column.label}</TH>
                  ))}
                </THead>
                <TBody>
                  {report.sample.map((sampleRow, i) => (
                    <TR key={i}>
                      {columns.map((column) => (
                        <TD key={column.field} className="text-ink-muted">
                          {sampleRow[column.field] ?? "—"}
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

/** What happened, in numbers. */
function Summary({ state }: { state: ImportState }) {
  const report = state.report!;
  const imported = state.mode === "import";

  const cards = [
    { label: "Linhas no arquivo", value: report.total_rows, tone: "text-ink" },
    {
      label: imported ? "Importadas" : "Válidas",
      value: imported ? report.imported_count : report.valid_count,
      tone: "text-paid",
    },
    {
      label: imported ? "Não importadas" : "Com errorRow",
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
          (imported
            ? "border-paid/30 bg-paid-soft text-paid"
            : "border-accent/30 bg-accent-soft text-accent")
        }
      >
        {imported
          ? `Importação concluída: ${report.imported_count.toLocaleString("pt-BR")} de ${report.total_rows.toLocaleString("pt-BR")} linhas entraram.`
          : "Nada foi gravado ainda. Confira abaixo e confirme para importar."}
      </p>

      <dl className="grid gap-px overflow-hidden rounded-lg border border-rule bg-rule sm:grid-cols-3">
        {cards.map((card) => (
          <div key={card.label} className="bg-surface px-4 py-3">
            <dt className="text-xs font-medium uppercase tracking-wide text-ink-muted">
              {card.label}
            </dt>
            <dd className={`mt-1 text-2xl font-semibold ${card.tone}`}>
              {card.value.toLocaleString("pt-BR")}
            </dd>
          </div>
        ))}
      </dl>
    </section>
  );
}
