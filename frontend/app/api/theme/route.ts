import { NextResponse, type NextRequest } from "next/server";

import { isTheme, THEME_COOKIE, THEME_MAX_AGE } from "@/lib/theme";

/**
 * Troca o tema da interface.
 *
 * Route Handler, e não Server Action, contrariando a regra geral deste projeto
 * de que mutação usa Action. A exceção está na própria regra: Route Handler é
 * para o que o browser precisa NAVEGAR, e o tema precisa.
 *
 * O motivo é concreto e foi medido na tela. O tema vive em `data-theme` no
 * `<html>`, que é renderizado pelo layout raiz. Numa atualização suave — que é
 * o que uma Server Action provoca — o React atualiza a árvore mas não
 * reconcilia atributo do elemento `<html>`: o cookie era gravado, o servidor
 * já respondia o tema novo, e o atributo continuava o antigo até alguém
 * recarregar a página.
 *
 * Com `<form method="post">` apontando para cá, o browser navega de verdade, o
 * layout raiz é executado no servidor e o `<html>` chega pronto. De quebra, o
 * seletor passa a funcionar sem JavaScript nenhum.
 */
export async function POST(request: NextRequest): Promise<NextResponse> {
    const form = await request.formData();
    const escolhido = form.get("theme");
    const theme = typeof escolhido === "string" && isTheme(escolhido) ? escolhido : "system";

    const response = new NextResponse(null, {
        // 303 e não 307: o retorno tem que virar GET. Um 307 repetiria o POST
        // na página de destino.
        status: 303,
        // Location RELATIVO, e não NextResponse.redirect().
        //
        // `redirect()` exige URL absoluta, e dentro do container
        // `request.nextUrl.origin` resolve para o endereço de bind
        // (http://0.0.0.0:3000) e não para o host que o browser usou. O
        // browser seguiria para OUTRA ORIGEM, não mandaria o cookie de sessão
        // junto, e o usuário cairia no login a cada troca de tema — foi
        // exatamente o que aconteceu na primeira versão. É a mesma armadilha
        // que o handler de expiração de sessão já documenta.
        headers: { Location: destino(request) },
    });

    response.cookies.set(THEME_COOKIE, theme, {
        maxAge: THEME_MAX_AGE,
        sameSite: "lax",
        path: "/",
        // Sem httpOnly, ao contrário do cookie de sessão: não há o que
        // proteger numa preferência de aparência.
    });

    return response;
}

/**
 * O caminho de onde o usuário veio, para devolvê-lo ao mesmo lugar.
 *
 * Duas guardas, e as duas importam:
 *
 * O host do `Referer` precisa bater com o header `Host` — que é o host que o
 * BROWSER usou, e não `request.nextUrl.origin`, que dentro do container é o
 * endereço de bind. Sem isso, um formulário hospedado em outro site escolheria
 * em que página interna o usuário aterrissa depois de trocar o tema.
 *
 * E volta só o caminho, nunca a URL inteira: o Location é relativo de
 * propósito, pela mesma razão do host acima.
 */
function destino(request: NextRequest): string {
    const referer = request.headers.get("referer");
    const host = request.headers.get("host");

    if (referer && host) {
        try {
            const url = new URL(referer);

            if (url.host === host && !url.pathname.startsWith("//")) {
                return `${url.pathname}${url.search}`;
            }
        } catch {
            // Referer malformado cai no default.
        }
    }

    return "/";
}
