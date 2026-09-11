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

  try {
    await fetchAsUser(`/api/billings/${id}/payment`, {
      method: "POST",
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

    return { message: "Não foi possível registrar o pagamento." };
  }

  revalidatePath("/cobrancas");
  revalidatePath(`/cobrancas/${id}`);
  redirect(`/cobrancas/${id}?sucesso=pago`);
}
