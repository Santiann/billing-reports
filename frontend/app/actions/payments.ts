"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";

import { ApiError } from "@/lib/api";
import { fetchAsUser } from "@/lib/server-api";

export type PaymentFormState = {
  errors?: Record<string, string[]>;
  message?: string | null;
};

type ValidationPayload = {
  message?: string;
  errors?: Record<string, string[]>;
};

export async function registerPayment(
  id: number,
  _previous: PaymentFormState,
  formData: FormData,
): Promise<PaymentFormState> {
  const paymentDate = String(formData.get("payment_date") ?? "");
  const paidAmount = String(formData.get("paid_amount") ?? "");

  /*
   * A chave de idempotência vem do formulário, sorteada no browser.
   *
   * Ela não pode nascer aqui: uma Server Action reexecutada por retry de rede
   * rodaria este código de novo e sortearia outra chave, que é justamente o
   * caso que a chave existe para cobrir. Nascendo no cliente, o reenvio manda
   * a mesma — e o backend devolve o primeiro resultado em vez de cobrar duas
   * vezes.
   */
  const idempotencyKey = String(formData.get("idempotency_key") ?? "");

  try {
    await fetchAsUser(`/api/billings/${id}/payment`, {
      method: "POST",
      headers: idempotencyKey ? { "Idempotency-Key": idempotencyKey } : {},
      body: {
        // Campos vazios viram null para o backend aplicar o default: hoje,
        // e o valor atualizado calculado.
        payment_date: paymentDate || null,
        paid_amount: paidAmount || null,
      },
    });
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) {
      const payload = error.payload as ValidationPayload | null;

      return {
        errors: payload?.errors ?? {},
        message: payload?.message ?? "Verifique os campos destacados.",
      };
    }

    if (error instanceof ApiError && error.status === 401) {
      redirect("/api/auth/expire");
    }

    // 409: a primeira requisição desta mesma chave ainda está processando.
    // Não é falha — é cedo. Dizer "não foi possível" faria o usuário clicar
    // de novo, que é o oposto do que a situação pede.
    if (error instanceof ApiError && error.status === 409) {
      return {
        message: "Este pagamento já está sendo registrado. Aguarde um instante.",
      };
    }

    return { message: "Não foi possível registrar o pagamento." };
  }

  revalidatePath("/cobrancas");
  revalidatePath(`/cobrancas/${id}`);
  redirect(`/cobrancas/${id}?sucesso=pago`);
}

export type ReversalFormState = {
  message?: string | null;
};

/**
 * Estorna o pagamento.
 *
 * Mesma forma do registro de pagamento, inclusive a chave de idempotência
 * vinda do browser: o estorno é a outra operação em que repetir muda dinheiro
 * de lugar. Pagou, estornou, pagou de novo — um retry atrasado do estorno sem
 * chave desfaria o segundo pagamento.
 */
export async function reversePayment(
  id: number,
  _previous: ReversalFormState,
  formData: FormData,
): Promise<ReversalFormState> {
  const idempotencyKey = String(formData.get("idempotency_key") ?? "");

  try {
    await fetchAsUser(`/api/billings/${id}/reversal`, {
      method: "POST",
      headers: idempotencyKey ? { "Idempotency-Key": idempotencyKey } : {},
      body: {},
    });
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) {
      const payload = error.payload as ValidationPayload | null;

      // Não há campo no formulário: o erro possível é de estado, "esta
      // cobrança não está paga", e vem na chave status.
      return {
        message:
          payload?.errors?.status?.[0] ??
          payload?.message ??
          "Não foi possível estornar o pagamento.",
      };
    }

    if (error instanceof ApiError && error.status === 401) {
      redirect("/api/auth/expire");
    }

    if (error instanceof ApiError && error.status === 409) {
      return {
        message: "Este estorno já está sendo registrado. Aguarde um instante.",
      };
    }

    return { message: "Não foi possível estornar o pagamento." };
  }

  revalidatePath("/cobrancas");
  revalidatePath(`/cobrancas/${id}`);
  redirect(`/cobrancas/${id}?sucesso=estornado`);
}
