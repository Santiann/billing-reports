import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Exigido pelo stage `runner` do Dockerfile: o build emite .next/standalone
  // com um server.js e só as dependências realmente usadas, o que dispensa
  // carregar node_modules inteiro na imagem final.
  output: "standalone",

  experimental: {
    serverActions: {
      /*
       * O default é 1 MB, e a importação de CSV passa por Server Action.
       *
       * O backend aceita arquivo de até 20 MB; sem este teto combinado, um CSV
       * de 5 MB morreria no Next antes de chegar lá, com erro de payload em vez
       * da mensagem que o formulário sabe exibir. O valor é um pouco maior que
       * os 20 MB porque o limite conta o corpo HTTP cru, e o multipart
       * acrescenta fronteiras e cabeçalhos de parte.
       */
      bodySizeLimit: "21mb",
    },
  },
};

export default nextConfig;
