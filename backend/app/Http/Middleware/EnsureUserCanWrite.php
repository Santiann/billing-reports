<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks writing for whoever holds the read-only role.
 *
 * Middleware and not a Policy, and the choice has a reason: a Policy resolves authorisation PER
 * RECORD — "this user may edit THIS billing". The rule here is per ROLE and holds for every
 * record, so tying it to the route group keeps the list of protected endpoints visible in a
 * single file, instead of scattered across one policy class per model.
 *
 * The practical gain is `routes/api.php`: you can read which routes write by looking at the
 * file, and a new route outside the group jumps out.
 */
class EnsureUserCanWrite
{
    public function handle(Request $request, Closure $next): Response
    {
        // The absence of a user is `auth:sanctum`'s problem, which runs before and answers 401.
        // Arriving here with no user would mean middleware out of
        // ordem, e responder 403 esconderia esse erro.
        $user = $request->user();

        if ($user !== null && ! $user->role->canWrite()) {
            return response()->json([
                'message' => 'Seu perfil é de consulta e não permite esta operação.',
            ], 403);
        }

        return $next($request);
    }
}
