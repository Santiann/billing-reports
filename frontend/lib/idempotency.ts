/**
 * Draws the idempotency key for one write attempt.
 *
 * This exists because of a bug that only shows up outside `localhost`:
 * `crypto.randomUUID()` is restricted to a SECURE CONTEXT — HTTPS, or localhost
 * by browser exception. Served over plain HTTP on any other host — an IP on the
 * internal network, a Docker service name — the function is `undefined`, the form
 * handler died with a TypeError and the record-payment button did nothing. No
 * warning on screen, because the error happened before the submit.
 *
 * What surfaced it was the end-to-end test, which arrives via
 * `http://frontend:3000` rather than localhost.
 *
 * `crypto.getRandomValues` carries no such restriction, so the fallback path
 * builds the v4 UUID with it — cryptographic randomness either way, and no new
 * dependency.
 */
export function newIdempotencyKey(): string {
  if (typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }

  const bytes = crypto.getRandomValues(new Uint8Array(16));

  // Version 4 and RFC 4122 variant, the same bits randomUUID writes.
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;

  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0"));

  return [
    hex.slice(0, 4).join(""),
    hex.slice(4, 6).join(""),
    hex.slice(6, 8).join(""),
    hex.slice(8, 10).join(""),
    hex.slice(10, 16).join(""),
  ].join("-");
}
