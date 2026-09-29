import { apiFetchRaw } from "@/lib/api";
import { getSessionToken } from "@/lib/server-api";

/**
 * The PDF report download.
 *
 * The same reason as the CSV for being a Route Handler: the token lives in an httpOnly
 * cookie and the browser does not have it.
 *
 * The difference: the PDF can be refused with a 422 when the scope exceeds the cap.
 * That 422's body is passed through to the browser rather than becoming a generic
 * error — it is what says how many billings there are, what the limit is, and that the
 * CSV has none.
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
