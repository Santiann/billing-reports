import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Exigido pelo stage `runner` do Dockerfile: o build emite .next/standalone
  // com um server.js e só as dependências realmente usadas, o que dispensa
  // carregar node_modules inteiro na imagem final.
  output: "standalone",

  /*
   * Origens que o servidor de DESENVOLVIMENTO aceita além de localhost.
   *
   * O Next bloqueia por padrão as requisições a recursos de desenvolvimento —
   * `/_next/hmr` entre eles — vindas de host diferente daquele em que subiu.
   * Os testes de ponta a ponta rodam num container e chegam por
   * `http://frontend:3000`, o nome do serviço no Compose: sem esta linha o HMR
   * é recusado, a hidratação não conclui e nenhum formulário responde a
   * clique. Foi o Playwright que mostrou, e o log do próprio container nomeou
   * a opção.
   *
   * Não afeta produção: lá não há recurso de desenvolvimento a proteger.
   */
  allowedDevOrigins: ["frontend"],

  /*
   * Cabeçalhos de segurança da aplicação.
   *
   * Sem CSP, e a ausência é uma decisão: o servidor de desenvolvimento do Next
   * precisa de `unsafe-eval` e de estilo inline, então uma política que
   * valesse só em produção iria para o ar sem nunca ter sido exercitada aqui —
   * e CSP que ninguém testou quebra a aplicação no pior momento. A origem da
   * API, que serve conteúdo estático e não tem script nenhum, ganhou CSP
   * completo no nginx.
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
