import { apiFetchRaw } from "@/lib/api";
import { getSessionToken } from "@/lib/server-api";

/**
 * The CSV report download.
 *
 * It has to be a Route Handler, not a Server Action nor a direct link to Laravel: the
 * browser does not have the token — it lives in an httpOnly cookie — so it cannot call
 * the export endpoint on its own. Here the server attaches the Bearer and returns the
 * body as a stream.
 *
 * The body is passed through unread: `upstream.body` is a ReadableStream, and
 * consuming it to resend afterwards would hold the whole file in memory, undoing the
 * streaming the backend implemented.
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
    // A validation error arrives as JSON; passing the body through avoids hiding the
    // cause behind a generic message.
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
