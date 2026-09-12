import type { Metadata } from "next";
import { IBM_Plex_Mono, IBM_Plex_Sans, Instrument_Serif } from "next/font/google";
import "./globals.css";

/**
 * Três famílias, cada uma com um trabalho.
 *
 * IBM Plex Sans na interface: humanista, desenhada para leitura densa, e com
 * algarismo tabular de verdade — o que importa numa tela cheia de coluna de
 * valor. IBM Plex Mono no dado: id, documento e dinheiro alinhados. Instrument
 * Serif no título, que é o que dá cara ao produto.
 *
 * `next/font` baixa e hospeda as fontes no build, então não há requisição a
 * servidor de terceiro em runtime nem salto de layout ao carregar.
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

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="pt-BR"
      className={`${display.variable} ${sans.variable} ${mono.variable} h-full antialiased`}
    >
      <body className="flex min-h-full flex-col">{children}</body>
    </html>
  );
}
