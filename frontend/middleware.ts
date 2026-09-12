import { NextResponse, type NextRequest } from "next/server";

import { SESSION_COOKIE } from "@/lib/session";

/**
 * Proteção de rota pela presença do cookie de sessão.
 *
 * O middleware não valida o token — validar é trabalho do Laravel, em toda
 * requisição de dado. Aqui só se decide quem vê a tela de login e quem vê a
 * aplicação. Um cookie forjado não abre nada: a API responde 401 e o Server
 * Component manda de volta para o login.
 */

/** Abertas a quem não tem sessão. */
const PUBLIC_ROUTES = ["/login", "/apresentacao"];

/**
 * Abertas SÓ para quem não tem sessão.
 *
 * A tela de login não faz sentido para quem já entrou. A apresentação faz:
 * é página pública, e quem está logado pode querer abri-la.
 */
const GUEST_ONLY = ["/login"];

export function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const hasSession = Boolean(request.cookies.get(SESSION_COOKIE)?.value);

  /*
   * A raiz atende duas plateias.
   *
   * Com sessão, `/` é a aplicação — o dashboard, protegido como sempre. Sem
   * sessão, ela mostra a apresentação em vez de empurrar para o login: quem
   * chega pela primeira vez precisa saber o que é isto antes de ver um
   * formulário de senha.
   *
   * `rewrite` e não `redirect`, e a diferença importa: o endereço continua `/`.
   * Um redirect para `/apresentacao` mudaria a URL na barra e faria o botão
   * "voltar" do browser brigar com o login.
   */
  if (pathname === "/" && !hasSession) {
    const url = request.nextUrl.clone();
    url.pathname = "/apresentacao";

    return NextResponse.rewrite(url);
  }

  if (!hasSession && !PUBLIC_ROUTES.includes(pathname)) {
    const loginUrl = request.nextUrl.clone();
    loginUrl.pathname = "/login";
    loginUrl.search = "";
    // Para devolver o usuário ao destino original depois do login.
    if (pathname !== "/") {
      loginUrl.searchParams.set("redirect", pathname);
    }

    return NextResponse.redirect(loginUrl);
  }

  if (hasSession && GUEST_ONLY.includes(pathname)) {
    const homeUrl = request.nextUrl.clone();
    homeUrl.pathname = "/";
    homeUrl.search = "";

    return NextResponse.redirect(homeUrl);
  }

  return NextResponse.next();
}

export const config = {
  // Fora: os Route Handlers (que precisam responder deslogado, senão não há
  // como fazer login), os assets e os arquivos estáticos.
  matcher: ["/((?!api/|_next/static|_next/image|favicon.ico|.*\\.svg$).*)"],
};
