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
const PUBLIC_ROUTES = ["/login"];

export function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const hasSession = Boolean(request.cookies.get(SESSION_COOKIE)?.value);
  const isPublicRoute = PUBLIC_ROUTES.includes(pathname);

  if (!hasSession && !isPublicRoute) {
    const loginUrl = request.nextUrl.clone();
    loginUrl.pathname = "/login";
    loginUrl.search = "";
    // Para devolver o usuário ao destino original depois do login.
    if (pathname !== "/") {
      loginUrl.searchParams.set("redirect", pathname);
    }

    return NextResponse.redirect(loginUrl);
  }

  if (hasSession && isPublicRoute) {
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
