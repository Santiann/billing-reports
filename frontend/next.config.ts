import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Required by the Dockerfile's `runner` stage: the build emits .next/standalone
  // with a server.js and only the dependencies actually used, which saves carrying
  // the whole node_modules into the final image.
  output: "standalone",

  /*
   * Origins the DEVELOPMENT server accepts besides localhost.
   *
   * Next blocks by default requests for development resources — `/_next/hmr` among
   * them — coming from a host other than the one it started on. The end-to-end tests
   * run in a container and arrive via `http://frontend:3000`, the service name in
   * Compose: without this line HMR is refused, hydration never completes and no form
   * responds to a click. Playwright surfaced it, and the container's own log named
   * the option.
   *
   * It does not affect production: there are no development resources to protect
   * there.
   */
  allowedDevOrigins: ["frontend"],

  /*
   * The application's security headers.
   *
   * No CSP, and the absence is a decision: Next's development server needs
   * `unsafe-eval` and inline styles, so a policy that only applied in production
   * would go live having never been exercised here — and a CSP nobody tested breaks
   * the application at the worst moment. The API's origin, which serves static
   * content and has no scripts at all, got a full CSP in nginx.
   */
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "X-Frame-Options", value: "DENY" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          {
            key: "Permissions-Policy",
            value: "camera=(), microphone=(), geolocation=()",
          },
        ],
      },
    ];
  },

  experimental: {
    serverActions: {
      /*
       * The default is 1 MB, and CSV importing goes through a Server Action.
       *
       * The backend accepts files up to 20 MB; without this matching cap, a 5 MB CSV
       * would die in Next before reaching it, with a payload error instead of the
       * message the form knows how to show. The value is slightly above 20 MB because
       * the limit counts the raw HTTP body, and multipart adds boundaries and
       * per-part headers.
       */
      bodySizeLimit: "21mb",
    },
  },
};

export default nextConfig;
