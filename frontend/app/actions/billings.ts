"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";

import { ApiError } from "@/lib/api";
import { fetchAsUser } from "@/lib/server-api";

export type BillingFormState = {
  errors?: Record<string, string[]>;
  message?: string | null;
};

function readForm(formData: FormData) {
  return {
    customer_id: String(formData.get("customer_id") ?? ""),
    description: String(formData.get("description") ?? ""),
    original_amount: String(formData.get("original_amount") ?? ""),
    monthly_interest_rate: String(formData.get("monthly_interest_rate") ?? ""),
    issue_date: String(formData.get("issue_date") ?? ""),
    due_date: String(formData.get("due_date") ?? ""),
  };
}

type ValidationPayload = {
  message?: string;
  errors?: Record<string, string[]>;
};

function toFormState(error: unknown, fallback: string): BillingFormState {
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

  return { message: fallback };
}

export async function createBilling(
  _previous: BillingFormState,
  formData: FormData,
): Promise<BillingFormState> {
  try {
    await fetchAsUser("/api/billings", {
      method: "POST",
      body: readForm(formData),
    });
  } catch (error) {
    return toFormState(error, "Não foi possível cadastrar a cobrança.");
  }

  revalidatePath("/cobrancas");
  redirect("/cobrancas?sucesso=cobranca-criada");
}

export async function updateBilling(
  id: number,
  _previous: BillingFormState,
  formData: FormData,
): Promise<BillingFormState> {
  try {
    await fetchAsUser(`/api/billings/${id}`, {
      method: "PUT",
      body: readForm(formData),
    });
  } catch (error) {
    return toFormState(error, "Não foi possível salvar as alterações.");
  }

  revalidatePath("/cobrancas");
  revalidatePath(`/cobrancas/${id}`);
  redirect(`/cobrancas/${id}?sucesso=editado`);
}
