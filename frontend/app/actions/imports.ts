"use server";

import { revalidatePath } from "next/cache";

import { ApiError } from "@/lib/api";
import { fetchAsUser } from "@/lib/server-api";
import type { ImportReport, ImportState } from "@/types/import";

/**
 * Analyses or imports a CSV.
 *
 * The same action does both, decided by the button that submitted the form. The file
 * is resent on confirmation rather than being held on the server between the preview
 * and the confirm — the browser's input still has the file, and that choice does
 * away with a temporary directory, a session identifier, an expiry and cleaning up
 * abandoned files.
 *
 * The cost is one extra upload. For a customer CSV it is trivial, and the
 * confirmation's report is produced by the same code as the preview — so what the
 * user saw is what will happen.
 */
async function submit(
  resource: "customers" | "billings",
  formData: FormData,
): Promise<ImportState> {
  const file = formData.get("file");
  const mode = formData.get("step") === "import" ? "import" : "preview";

  if (!(file instanceof File) || file.size === 0) {
    return { errors: { file: ["Selecione um arquivo CSV."] } };
  }

  const body = new FormData();
  body.set("file", file);

  try {
    const report = await fetchAsUser<ImportReport>(
      `/api/${resource}/import${mode === "preview" ? "?preview=1" : ""}`,
      { method: "POST", body },
    );

    if (mode === "import") {
      revalidatePath(`/${resource === "customers" ? "clientes" : "cobrancas"}`);
    }

    return { mode, report };
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) {
      const payload = error.payload as {
        message?: string;
        errors?: Record<string, string[]>;
      };

      return { message: payload.message, errors: payload.errors };
    }

    return { message: "Não foi possível processar o arquivo." };
  }
}

export async function importCustomers(
  _previous: ImportState,
  formData: FormData,
): Promise<ImportState> {
  return submit("customers", formData);
}

export async function importBillings(
  _previous: ImportState,
  formData: FormData,
): Promise<ImportState> {
  return submit("billings", formData);
}
