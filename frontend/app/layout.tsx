import type { Metadata } from "next";
import { IBM_Plex_Mono, IBM_Plex_Sans, Instrument_Serif } from "next/font/google";
import { cookies } from "next/headers";

import { isTheme, THEME_COOKIE } from "@/lib/theme";
import "./globals.css";

/**
 * Three families, each with a job.
 *
 * IBM Plex Sans for the interface: humanist, designed for dense reading, and with
 * genuine tabular figures — which is what matters on a screen full of amount columns.
 * IBM Plex Mono for data: ids, documents and money aligned. Instrument Serif for
 * titles, which is what gives the product a face.
 *
 * `next/font` downloads and self-hosts the fonts at build time, so there is no
 * request to a third-party server at runtime and no layout shift on load.
 */
const display = Instrument_Serif({
  variable: "--fonte-display",
  subsets: ["latin"],
  weight: "400",
  display: "swap",
});

const sans = IBM_Plex_Sans({
  variable: "--fonte-sans",
  subsets: ["latin"],
  weight: ["400", "500", "600"],
  display: "swap",
});

const mono = IBM_Plex_Mono({
  variable: "--fonte-mono",
  subsets: ["latin"],
  weight: ["400", "500"],
  display: "swap",
});

export const metadata: Metadata = {
  title: "Gerador de Relatórios",
  description: "Faturamento, cobranças e relatório por período.",
};

export default async function RootLayout({ children }: LayoutProps<"/">) {
  /*
   * The theme is read from the cookie HERE, on the server, not in a client effect.
   *
   * That is what eliminates the flash: with `localStorage` the page renders in the
   * wrong theme and switches after hydration, and the common way out is an inline
   * script in the <head> that the bundler cannot see. Reading the cookie in the
   * layout, the `<html>` leaves the very first response with the right attribute.
   *
   * "system" does not become an attribute: its absence is what hands the decision
   * back to `prefers-color-scheme`.
   */
  const chosen = (await cookies()).get(THEME_COOKIE)?.value;
  const theme = isTheme(chosen) ? chosen : "system";

  return (
    <html
      lang="pt-BR"
      data-theme={theme === "system" ? undefined : theme}
      className={`${display.variable} ${sans.variable} ${mono.variable} h-full antialiased`}
    >
      <body className="flex min-h-full flex-col">{children}</body>
    </html>
  );
}
