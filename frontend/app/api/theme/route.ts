import { NextResponse, type NextRequest } from "next/server";

import { isTheme, THEME_COOKIE, THEME_MAX_AGE } from "@/lib/theme";

/**
 * Switches the interface theme.
 *
 * A Route Handler, not a Server Action, going against this project's general rule
 * that mutations use an Action. The exception is in the rule itself: Route Handlers
 * are for what the browser needs to NAVIGATE to, and the theme needs that.
 *
 * The reason is concrete and was observed on screen. The theme lives in `data-theme`
 * on the `<html>`, which the root layout renders. On a soft update — which is what a
 * Server Action causes — React updates the tree but does not reconcile attributes on
 * the `<html>` element: the cookie was written, the server was already answering with
 * the new theme, and the attribute stayed on the old one until someone reloaded the
 * page.
 *
 * With a `<form method="post">` pointing here, the browser really navigates, the root
 * layout executes on the server and the `<html>` arrives ready. As a bonus, the
 * selector works with no JavaScript at all.
 */
export async function POST(request: NextRequest): Promise<NextResponse> {
    const form = await request.formData();
    const submitted = form.get("theme");
    const theme = typeof submitted === "string" && isTheme(submitted) ? submitted : "system";

    const response = new NextResponse(null, {
        // 303 and not 307: the return has to become a GET. A 307 would repeat the
        // POST on the destination page.
        status: 303,
        // A RELATIVE Location, and not NextResponse.redirect().
        //
        // `redirect()` requires an absolute URL, and inside the container
        // `request.nextUrl.origin` resolves to the bind address
        // (http://0.0.0.0:3000) rather than the host the browser used. The browser
        // would follow to ANOTHER ORIGIN, would not send the session cookie along,
        // and the user would land on the login on every theme switch — which is
        // exactly what happened in the first version. It is the same trap the
        // session expiry handler already documents.
        headers: { Location: target(request) },
    });

    response.cookies.set(THEME_COOKIE, theme, {
        maxAge: THEME_MAX_AGE,
        sameSite: "lax",
        path: "/",
        // No httpOnly, unlike the session cookie: there is nothing to protect in an
        // appearance preference.
    });

    return response;
}

/**
 * The path the user came from, so they can be returned to the same place.
 *
 * Two guards, and both matter:
 *
 * The `Referer`'s host has to match the `Host` header — which is the host the
 * BROWSER used, and not `request.nextUrl.origin`, which inside the container is the
 * bind address. Without that, a form hosted on another site would get to choose which
 * internal page the user lands on after switching themes.
 *
 * And only the path comes back, never the whole URL: the Location is relative on
 * purpose, for the same reason as the host above.
 */
function target(request: NextRequest): string {
    const referer = request.headers.get("referer");
    const host = request.headers.get("host");

    if (referer && host) {
        try {
            const url = new URL(referer);

            if (url.host === host && !url.pathname.startsWith("//")) {
                return `${url.pathname}${url.search}`;
            }
        } catch {
            // A malformed Referer falls through to the default.
        }
    }

    return "/";
}
