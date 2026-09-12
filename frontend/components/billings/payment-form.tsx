"use client";

import { useActionState, useRef, useTransition, type FormEvent } from "react";

import { registerPayment, type PaymentFormState } from "@/app/actions/payments";
import { Button } from "@/components/ui/button";
import { Field, FieldError, Input } from "@/components/ui/field";
import { formatCurrency } from "@/lib/format";
import type { Billing } from "@/types/billing";

const INITIAL: PaymentFormState = {};

export function PaymentForm({ billing }: { billing: Billing }) {
  const action = registerPayment.bind(null, billing.id);
  const [state, formAction, isActionPending] = useActionState(action, INITIAL);
  const [isTransitionPending, startTransition] = useTransition();

  const isPending = isActionPending || isTransitionPending;

  /*
   * A chave de idempotência desta tentativa.
   *
   * Ela é sorteada uma vez e reaproveitada enquanto o conteúdo do formulário
   * não mudar. É essa regra que separa os dois casos:
   *
   *   mesmo conteúdo   -> mesma chave -> o backend devolve o primeiro
   *                       resultado em vez de cobrar de novo. É o duplo
   *                       clique, e o reenvio depois de a conexão cair.
   *
   *   conteúdo mudou   -> chave nova -> é outra operação. Quem corrigiu a data
   *                       depois de um erro está pedindo outra coisa, e
   *                       reaproveitar a chave devolveria o erro antigo.
   */
  const tentativa = useRef<{ chave: string; conteudo: string } | null>(null);

  function chaveDaTentativa(dados: FormData): string {
    const conteudo = JSON.stringify([
      dados.get("payment_date"),
      dados.get("paid_amount"),
    ]);

    if (tentativa.current?.conteudo !== conteudo) {
      tentativa.current = { chave: crypto.randomUUID(), conteudo };
    }

    return tentativa.current.chave;
  }

  /*
   * O envio passa por `onSubmit` porque a chave só pode nascer no browser:
   * `crypto.randomUUID()` durante a renderização daria um valor no servidor e
   * outro na hidratação. Aqui ela é sorteada no clique, quando só existe um
   * lado. De quebra o formulário não é resetado pelo React, então os valores
   * digitados sobrevivem a um erro de validação.
   */
  function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();

    const dados = new FormData(evento.currentTarget);
    dados.set("idempotency_key", chaveDaTentativa(dados));

    startTransition(() => formAction(dados));
  }

  return (
    <form onSubmit={enviar} className="flex flex-col gap-5" noValidate>
      {state.message ? (
        <p
          role="alert"
          className="rounded-md border border-overdue/30 bg-overdue-soft px-3 py-2 text-sm text-overdue"
        >
          {state.message}
        </p>
      ) : null}

      {/* O erro de "já está paga" vem na chave status, sem campo na tela. */}
      <FieldError messages={state.errors?.status} />

      <div className="grid gap-5 sm:grid-cols-2">
        <Field
          label="Data do pagamento"
          htmlFor="payment_date"
          hint="Em branco usa hoje. Os juros congelam na data informada."
          errors={state.errors?.payment_date}
        >
          {/* Os juros congelam na data informada, não em hoje: pagamento
              retroativo produz o valor daquele dia. */}
          <Input
            id="payment_date"
            name="payment_date"
            type="date"
            disabled={isPending}
            aria-invalid={state.errors?.payment_date ? true : undefined}
          />
        </Field>

        <Field
          label="Valor recebido (R$)"
          htmlFor="paid_amount"
          hint={`Em branco usa o valor atualizado, ${formatCurrency(billing.updated_amount)}.`}
          errors={state.errors?.paid_amount}
        >
          <Input
            id="paid_amount"
            name="paid_amount"
            type="number"
            step="0.01"
            min="0.01"
            placeholder={billing.updated_amount}
            disabled={isPending}
            aria-invalid={state.errors?.paid_amount ? true : undefined}
            className="font-mono tabular-nums"
          />
        </Field>
      </div>

      <div className="border-t border-rule pt-5">
        <Button type="submit" disabled={isPending}>
          {isPending ? "Registrando…" : "Registrar pagamento"}
        </Button>
      </div>
    </form>
  );
}
