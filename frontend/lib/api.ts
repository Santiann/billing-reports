/**
 * The single point of contact with the Laravel API.
 *
 * There are two origins and they are not interchangeable. Using the wrong one is
 * the most likely bug in this project, and it only shows up inside Docker —
 * outside Compose the two URLs coincide and the mistake stays invisible.
 *
 *   Server Components, Route Handlers, middleware -> API_URL_INTERNAL
 *     They run inside the Compose network and resolve the backend by service
 *     name (http://backend, which is nginx).
 *
 *   Code running in the browser -> NEXT_PUBLIC_API_URL
 *     It cannot see the Compose network; it resolves through the port published
 *     on the host.
 *
 * No component builds an API URL by hand. Everything goes through here.
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
        ? "API_URL_INTERNAL is not set. It is required in Server Components, Route Handlers and middleware."
        : "NEXT_PUBLIC_API_URL is not set. It is required in code that runs in the browser.",
    );
  }

  return base.replace(/\/$/, "");
}

/**
 * The raw API response, unparsed.
 *
 * For downloads: the body is passed through to the browser as a stream, and
 * reading it whole to convert it into JSON would undo the streaming the backend
 * implements. It still goes through here so origin resolution stays in one place.
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
  /** Sanctum token. In a Server Component it comes from the httpOnly cookie. */
  token?: string | null;
  body?: unknown;
};

export async function apiFetch<T>(
  path: string,
  { token, body, headers, ...init }: ApiFetchOptions = {},
): Promise<T> {
  /*
   * FormData goes raw, with no Content-Type.
   *
   * Serialising it as JSON would lose the file, and declaring the Content-Type by
   * hand would break the upload in a way that is hard to see: multipart needs a
   * `boundary` only whoever assembles the body knows. Leaving the header absent
   * is what makes fetch fill it in with the right boundary.
   */
  const multipart = body instanceof FormData;

  const response = await fetch(`${resolveBaseUrl()}${path}`, {
    ...init,
    headers: {
      Accept: "application/json",
      ...(body !== undefined && !multipart
        ? { "Content-Type": "application/json" }
        : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
    ...(body !== undefined
      ? { body: multipart ? (body as FormData) : JSON.stringify(body) }
      : {}),
    // The report is live data: nothing here may be served from cache.
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
