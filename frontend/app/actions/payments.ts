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
   * The idempotency key comes from the form, drawn in the browser.
   *
   * It cannot be born here: a Server Action re-executed by a network retry would run
   * this code again and draw another key, which is exactly the case the key exists to
   * cover. Born on the client, the resend sends the same one — and the backend returns
   * the first result instead of charging twice.
   */
  const idempotencyKey = String(formData.get("idempotency_key") ?? "");

  try {
    await fetchAsUser(`/api/billings/${id}/payment`, {
      method: "POST",
      headers: idempotencyKey ? { "Idempotency-Key": idempotencyKey } : {},
      body: {
        // Empty fields become null so the backend applies its defaults: today,
        // and the computed updated amount.
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

    // 409: the first request with this same key is still processing. It is not a
    // failure — it is early. Saying "could not" would make the user click again,
    // which is the opposite of what the situation calls for.
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
 * Reverses the payment.
 *
 * The same shape as recording a payment, idempotency key from the browser included:
 * a reversal is the other operation where repeating moves money around. Paid,
 * reversed, paid again — a late reversal retry with no key would undo the second
 * payment.
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

      // There is no field in the form: the possible error is one of state, "this
      // billing is not paid", and it arrives under the status key.
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
