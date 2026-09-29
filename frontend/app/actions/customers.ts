"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";

import { ApiError } from "@/lib/api";
import { fetchAsUser } from "@/lib/server-api";

/**
 * Customer mutations.
 *
 * They run on the server, read the httpOnly cookie and attach the Bearer. The
 * browser does not have the token, so it would have no way to call Laravel directly —
 * the same reason the login goes through a Route Handler.
 *
 * Laravel's validation errors (422) come back field by field for the form to
 * display, rather than collapsing into a generic message.
 */
export type CustomerFormState = {
  errors?: Record<string, string[]>;
  message?: string | null;
};

function readForm(formData: FormData) {
  return {
    name: String(formData.get("name") ?? ""),
    document: String(formData.get("document") ?? ""),
    email: String(formData.get("email") ?? ""),
    status: String(formData.get("status") ?? ""),
  };
}

type ValidationPayload = {
  message?: string;
  errors?: Record<string, string[]>;
};

function toFormState(error: unknown, fallback: string): CustomerFormState {
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

export async function createCustomer(
  _previous: CustomerFormState,
  formData: FormData,
): Promise<CustomerFormState> {
  try {
    await fetchAsUser("/api/customers", {
      method: "POST",
      body: readForm(formData),
    });
  } catch (error) {
    return toFormState(error, "Não foi possível cadastrar o cliente.");
  }

  // redirect() outside the try: it signals by throwing, and being caught by the
  // catch above would turn a successful create into "erro ao cadastrar".
  revalidatePath("/clientes");
  redirect("/clientes?sucesso=cliente-criado");
}

export async function updateCustomer(
  id: number,
  _previous: CustomerFormState,
  formData: FormData,
): Promise<CustomerFormState> {
  try {
    await fetchAsUser(`/api/customers/${id}`, {
      method: "PUT",
      body: readForm(formData),
    });
  } catch (error) {
    return toFormState(error, "Não foi possível salvar as alterações.");
  }

  revalidatePath("/clientes");
  revalidatePath(`/clientes/${id}`);
  redirect(`/clientes/${id}?sucesso=editado`);
}
