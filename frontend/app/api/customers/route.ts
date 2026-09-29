import { NextResponse } from "next/server";

import { ApiError } from "@/lib/api";
import { fetchAsUser } from "@/lib/server-api";

/**
 * The customer search behind the billing form's picker.
 *
 * It exists as a Route Handler because the caller is browser code, which does not have
 * the token — and loading all five thousand customers into a <select> is not an option.
 * The component types, this searches, and only the first results come down.
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
