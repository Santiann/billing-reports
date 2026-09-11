/**
 * Ponto único de contato com a API do Laravel.
 *
 * Existem duas origens e elas não são intercambiáveis. Usar a errada é o bug
 * mais provável deste projeto, e ele só se manifesta dentro do Docker — fora
 * do Compose as duas URLs coincidem e o erro fica invisível.
 *
 *   Server Components, Route Handlers, middleware -> API_URL_INTERNAL
 *     Rodam dentro da rede do Compose e resolvem o backend pelo nome do
 *     serviço (http://backend, que é o nginx).
 *
 *   Código executando no browser -> NEXT_PUBLIC_API_URL
 *     Não enxerga a rede do Compose; resolve pela porta publicada no host.
 *
 * Nenhum componente monta URL de API na mão. Tudo passa por aqui.
 */

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly payload: unknown,
    message: string,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

function resolveBaseUrl(): string {
  const isServer = typeof window === "undefined";
  const base = isServer
    ? process.env.API_URL_INTERNAL
    : process.env.NEXT_PUBLIC_API_URL;

  if (!base) {
    throw new Error(
      isServer
        ? "API_URL_INTERNAL não definida. Ela é obrigatória em Server Components, Route Handlers e middleware."
        : "NEXT_PUBLIC_API_URL não definida. Ela é obrigatória no código que roda no browser.",
    );
  }

  return base.replace(/\/$/, "");
}

/**
 * Resposta crua da API, sem parse.
 *
 * Para downloads: o corpo é repassado ao browser como stream, e lê-lo inteiro
 * para converter em JSON anularia o streaming que o backend implementa.
 * Continua passando por aqui para a resolução de origem ficar num lugar só.
 */
export async function apiFetchRaw(
  path: string,
  { token, headers, ...init }: Omit<ApiFetchOptions, "body"> = {},
): Promise<Response> {
  return fetch(`${resolveBaseUrl()}${path}`, {
    ...init,
    headers: {
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
    cache: "no-store",
  });
}

export type ApiFetchOptions = Omit<RequestInit, "body"> & {
  /** Token Sanctum. Em Server Component vem do cookie httpOnly. */
  token?: string | null;
  body?: unknown;
};

export async function apiFetch<T>(
  path: string,
  { token, body, headers, ...init }: ApiFetchOptions = {},
): Promise<T> {
  const response = await fetch(`${resolveBaseUrl()}${path}`, {
    ...init,
    headers: {
      Accept: "application/json",
      ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
    ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    // Relatório é dado vivo: nada aqui pode servir de cache.
    cache: "no-store",
  });

  if (response.status === 204) {
    return undefined as T;
  }

  const payload: unknown = await response.json().catch(() => null);

  if (!response.ok) {
    const message =
      (payload as { message?: string } | null)?.message ??
      `A API respondeu ${response.status}.`;
    throw new ApiError(response.status, payload, message);
  }

  return payload as T;
}
