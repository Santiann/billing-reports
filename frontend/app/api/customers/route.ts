import { NextResponse } from "next/server";

import { ApiError } from "@/lib/api";
import { fetchAsUser } from "@/lib/server-api";

/**
 * Busca de clientes para o seletor do formulário de cobrança.
 *
 * Existe como Route Handler porque quem consulta é código de browser, que não
 * tem o token — e carregar os cinco mil clientes num <select> não é opção.
 * O componente digita, isto busca, e só os primeiros resultados descem.
 */
export async function GET(request: Request) {
  const search = new URL(request.url).searchParams.get("search") ?? "";

  const query = new URLSearchParams({ per_page: "20", sort: "name" });

  if (search) {
    query.set("search", search);
  }

  try {
    const data = await fetchAsUser(`/api/customers?${query.toString()}`);

    return NextResponse.json(data);
  } catch (error) {
    if (error instanceof ApiError) {
      return NextResponse.json(error.payload ?? { message: error.message }, {
        status: error.status,
      });
    }

    return NextResponse.json(
      { message: "Não foi possível buscar clientes." },
      { status: 502 },
    );
  }
}
