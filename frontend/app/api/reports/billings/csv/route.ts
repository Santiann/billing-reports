import { apiFetchRaw } from "@/lib/api";
import { getSessionToken } from "@/lib/server-api";

/**
 * Download do relatório em CSV.
 *
 * Precisa ser Route Handler, não Server Action nem link direto ao Laravel: o
 * browser não tem o token — ele vive num cookie httpOnly — então não consegue
 * chamar o endpoint de exportação por conta própria. Aqui o servidor anexa o
 * Bearer e devolve o corpo como stream.
 *
 * O corpo é repassado sem ser lido: `upstream.body` é um ReadableStream, e
 * consumi-lo para reenviar depois guardaria o arquivo inteiro em memória,
 * anulando o streaming que o backend implementou.
 */
export async function GET(request: Request) {
  const token = await getSessionToken();

  if (!token) {
    return new Response("Não autenticado.", { status: 401 });
  }

  const params = new URL(request.url).searchParams;

  const upstream = await apiFetchRaw(
    `/api/reports/billings/csv?${params.toString()}`,
    { token, headers: { Accept: "text/csv" } },
  );

  if (!upstream.ok) {
    // Erro de validação vem como JSON; repassar o corpo evita esconder a
    // causa atrás de uma mensagem genérica.
    return new Response(await upstream.text(), {
      status: upstream.status,
      headers: {
        "Content-Type": upstream.headers.get("Content-Type") ?? "application/json",
      },
    });
  }

  return new Response(upstream.body, {
    status: 200,
    headers: {
      "Content-Type": upstream.headers.get("Content-Type") ?? "text/csv; charset=UTF-8",
      "Content-Disposition":
        upstream.headers.get("Content-Disposition") ??
        'attachment; filename="relatorio-faturamento.csv"',
      "Cache-Control": "no-store",
    },
  });
}
