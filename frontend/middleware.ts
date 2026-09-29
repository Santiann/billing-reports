import { NextResponse, type NextRequest } from "next/server";

import { SESSION_COOKIE } from "@/lib/session";

/**
 * Route protection by the presence of the session cookie.
 *
 * The middleware does not validate the token — validating is Laravel's job, on
 * every data request. All that is decided here is who sees the login screen and who
 * sees the application. A forged cookie opens nothing: the API answers 401 and the
 * Server Component sends you back to the login.
 */

/** Open to whoever has no session. */
const PUBLIC_ROUTES = ["/login", "/apresentacao"];

/**
 * Open ONLY to whoever has no session.
 *
 * The login screen makes no sense to someone already signed in. The landing page
 * does: it is a public page, and a signed-in user may well want to open it.
 */
const GUEST_ONLY = ["/login"];

export function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const hasSession = Boolean(request.cookies.get(SESSION_COOKIE)?.value);

  /*
   * The root serves two audiences.
   *
   * With a session, `/` is the application — the dashboard, protected as always.
   * Without one, it shows the landing page instead of pushing you to the login:
   * whoever arrives for the first time needs to know what this is before seeing a
   * password form.
   *
   * `rewrite` and not `redirect`, and the difference matters: the address stays `/`.
   * A redirect to `/apresentacao` would change the URL in the bar and make the
   * browser's back button fight with the login.
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
    // So the user can be returned to the original destination after logging in.
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
  // Excluded: the Route Handlers (which have to answer while logged out, otherwise
  // there is no way to log in), the assets and the static files.
  matcher: ["/((?!api/|_next/static|_next/image|favicon.ico|.*\\.svg$).*)"],
};
