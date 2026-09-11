import { apiFetchRaw } from "@/lib/api";
import { getSessionToken } from "@/lib/server-api";

/**
 * Download do relatório em PDF.
 *
 * Mesmo motivo do CSV para ser Route Handler: o token vive num cookie
 * httpOnly e o browser não o tem.
 *
 * Diferença: o PDF pode ser recusado com 422 quando o recorte excede o teto.
 * O corpo desse 422 é repassado para o browser em vez de virar um erro
 * genérico — é ele que diz quantas cobranças há, qual o limite, e que o CSV
 * não tem limite.
 */
export async function GET(request: Request) {
  const token = await getSessionToken();

  if (!token) {
    return new Response("Não autenticado.", { status: 401 });
  }

  const params = new URL(request.url).searchParams;

  const upstream = await apiFetchRaw(
    `/api/reports/billings/pdf?${params.toString()}`,
    { token, headers: { Accept: "application/pdf" } },
  );

  if (!upstream.ok) {
    return new Response(await upstream.text(), {
      status: upstream.status,
      headers: {
        "Content-Type":
          upstream.headers.get("Content-Type") ?? "application/json",
      },
    });
  }

  return new Response(upstream.body, {
    status: 200,
    headers: {
      "Content-Type": "application/pdf",
      "Content-Disposition":
        upstream.headers.get("Content-Disposition") ??
        'attachment; filename="relatorio-faturamento.pdf"',
      "Cache-Control": "no-store",
    },
  });
}
