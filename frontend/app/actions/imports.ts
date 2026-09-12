"use server";

import { revalidatePath } from "next/cache";

import { ApiError } from "@/lib/api";
import { fetchAsUser } from "@/lib/server-api";
import type { ImportReport, ImportState } from "@/types/import";

/**
 * Analisa ou importa um CSV.
 *
 * A mesma action faz as duas coisas, decidido pelo botão que enviou o
 * formulário. O arquivo é reenviado na confirmação, em vez de ficar guardado no
 * servidor entre a prévia e o confirmar — o input do browser ainda tem o
 * arquivo, e essa escolha dispensa diretório temporário, identificador de
 * sessão, expiração e faxina de arquivo abandonado.
 *
 * O custo é um upload a mais. Para um CSV de clientes ele é trivial, e o
 * relatório da confirmação é gerado pelo mesmo código da prévia — então o que o
 * usuário viu é o que vai acontecer.
 */
async function enviar(
  recurso: "customers" | "billings",
  formData: FormData,
): Promise<ImportState> {
  const arquivo = formData.get("file");
  const modo = formData.get("acao") === "import" ? "import" : "preview";

  if (!(arquivo instanceof File) || arquivo.size === 0) {
    return { errors: { file: ["Selecione um arquivo CSV."] } };
  }

  const corpo = new FormData();
  corpo.set("file", arquivo);

  try {
    const report = await fetchAsUser<ImportReport>(
      `/api/${recurso}/import${modo === "preview" ? "?preview=1" : ""}`,
      { method: "POST", body: corpo },
    );

    if (modo === "import") {
      revalidatePath(`/${recurso === "customers" ? "clientes" : "cobrancas"}`);
    }

    return { mode: modo, report };
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
  return enviar("customers", formData);
}
