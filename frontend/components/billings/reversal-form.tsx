"use client";

import {
  useActionState,
  useRef,
  useState,
  useTransition,
  type FormEvent,
} from "react";

import { reversePayment, type ReversalFormState } from "@/app/actions/payments";
import { Button } from "@/components/ui/button";

const INITIAL: ReversalFormState = {};

/**
 * Estorno em dois passos, sem modal.
 *
 * O primeiro clique só abre a confirmação, que diz o que vai acontecer com o
 * dinheiro antes de acontecer. Modal seria mais um componente para uma
 * pergunta de uma linha, e tiraria de vista os valores pagos que estão logo
 * acima — que são justamente o que a pessoa precisa conferir antes de estornar.
 */
export function ReversalForm({ billingId }: { billingId: number }) {
  const action = reversePayment.bind(null, billingId);
  const [state, formAction, isActionPending] = useActionState(action, INITIAL);
  const [isTransitionPending, startTransition] = useTransition();
  const [confirmando, setConfirmando] = useState(false);

  const isPending = isActionPending || isTransitionPending;

  /*
   * Uma chave por montagem do formulário.
   *
   * O estorno não tem campos, então não há conteúdo que mude entre tentativas:
   * todo reenvio desta tela é a mesma operação. A chave nasce no clique, e não
   * na renderização, pelo mesmo motivo do formulário de pagamento — no
   * servidor e na hidratação o sorteio daria dois valores.
   */
  const chave = useRef<string | null>(null);

  function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();

    chave.current ??= crypto.randomUUID();

    const dados = new FormData();
    dados.set("idempotency_key", chave.current);

    startTransition(() => formAction(dados));
  }

  if (!confirmando) {
    return (
      <div className="flex flex-col gap-3">
        <p className="text-sm text-ink-muted">
          Para um pagamento que não se sustentou — cheque devolvido,
          transferência revertida, baixa lançada na cobrança errada.
        </p>
        <div>
          <Button variant="secondary" onClick={() => setConfirmando(true)}>
            Estornar pagamento
          </Button>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={enviar} className="flex flex-col gap-4">
      {state.message ? (
        <p
          role="alert"
          className="rounded-md border border-overdue/30 bg-overdue-soft px-3 py-2 text-sm text-overdue"
        >
          {state.message}
        </p>
      ) : null}

      <p className="text-sm text-ink">
        A cobrança volta a <strong>pendente</strong> e os juros voltam a correr
        desde o vencimento original. Os valores pagos saem da ficha e ficam
        registrados no histórico.
      </p>

      <div className="flex flex-wrap gap-3">
        {/* `danger`: no domínio, a cor de vencida já significa perda. */}
        <Button type="submit" variant="danger" disabled={isPending}>
          {isPending ? "Estornando…" : "Confirmar estorno"}
        </Button>
        <Button
          type="button"
          variant="secondary"
          disabled={isPending}
          onClick={() => setConfirmando(false)}
        >
          Cancelar
        </Button>
      </div>
    </form>
  );
}
