import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Exigido pelo stage `runner` do Dockerfile: o build emite .next/standalone
  // com um server.js e só as dependências realmente usadas, o que dispensa
  // carregar node_modules inteiro na imagem final.
  output: "standalone",
};

export default nextConfig;
