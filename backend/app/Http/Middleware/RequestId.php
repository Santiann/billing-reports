<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One identifier per request, in the response and in the log.
 *
 * It preserves what comes from outside. nginx already generates an `X-Request-Id` and prints it
 * in the access log; overwriting it here would cut the link between the edge's line and the
 * application's lines. The value comes back in the response header so a problem report can
 * quote it.
 */
class RequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = trim((string) $request->headers->get(self::HEADER)) ?: (string) Str::uuid();

        $request->headers->set(self::HEADER, $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
