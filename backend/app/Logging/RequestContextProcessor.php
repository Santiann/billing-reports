<?php

namespace App\Logging;

use App\Http\Middleware\RequestId;
use Illuminate\Support\Facades\Auth;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Puts on every log line who asked, what they asked for, and under which identifier.
 *
 * A processor, and not `Log::withContext()` in a middleware, because of `user_id`: group
 * middleware runs BEFORE `auth:sanctum`, so there the user does not exist yet and the field
 * would come out null in production — while in the tests, where `actingAs` resolves the user
 * earlier, it would appear to work. The processor is evaluated at each line's moment, when
 * authentication has already happened.
 *
 * `hasUser()` before `id()` on purpose: asking for the id would resolve the guard from the
 * logger, which would invert the order of things. A line logged before authentication — a
 * failed login attempt, for instance — comes out with no user, which is the truth.
 */
final class RequestContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = ['user_id' => Auth::hasUser() ? Auth::id() : null];

        $request = request();
        $identifier = $request->headers->get(RequestId::HEADER);

        /*
         * The header's presence is what says an HTTP request happened.
         *
         * `runningInConsole()` looked like the right question and is not: the suite runs
         * through artisan, so in console the tests would never see the context production
         * sees. And in a real command the container still exposes a synthetic `Request`, with
         * method GET and path "/", which would be noise — but without this header, because
         * the middleware is what sets it.
         */
        if ($identifier !== null) {
            $context += [
                'request_id' => $identifier,
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $request->ip(),
            ];
        }

        // The caller's context wins: if someone logged an explicit `user_id`, it is because
        // they meant that user, not the authenticated one.
        return $record->with(context: [...$context, ...$record->context]);
    }
}
