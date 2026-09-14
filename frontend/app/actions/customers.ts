"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";

import { ApiError } from "@/lib/api";
import { fetchAsUser } from "@/lib/server-api";

/**
 * Mutações de cliente.
 *
 * Rodam no servidor, leem o cookie httpOnly e anexam o Bearer. O browser não
 * tem o token, então não teria como chamar o Laravel direto — mesma razão
 * pela qual o login passa por Route Handler.
 *
 * Os erros de validação do Laravel (422) voltam campo a campo para o
 * formulário exibir, em vez de virarem uma mensagem genérica.
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

  // redirect() fora do try: ele sinaliza por exceção, e ser capturado pelo
  // catch acima viraria "erro ao cadastrar" num cadastro que deu certo.
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
