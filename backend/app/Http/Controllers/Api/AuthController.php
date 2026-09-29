<?php

namespace App\Http\Controllers\Api;

use App\Domain\Auth\LoginThrottle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function login(LoginRequest $request, LoginThrottle $throttle): JsonResponse
    {
        /*
         * The limit is checked after validation, and that is deliberate: a request with
         * neither email nor password is not an authentication attempt, and counting it would
         * let a client with a broken form lock out its own user.
         */
        $seconds = $throttle->blockedFor($request);

        if ($seconds !== null) {
            Log::warning('login.blocked', [
                'email' => $request->validated('email'),
                'retry_after' => $seconds,
            ]);

            return response()->json([
                'message' => "Muitas tentativas de login. Tente de novo em {$seconds} segundos.",
            ], 429)->header('Retry-After', (string) $seconds);
        }

        $user = User::where('email', $request->validated('email'))->first();

        // 401 and not 422: the payload is valid, what failed was the authentication.
        // A single message for an unknown email and a wrong password, so as not to
        // revelar quais e-mails existem.
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            $throttle->record($request);

            // The attempted email goes to the log: without it there is no way to tell someone
            // who mistyped their password from a sweep across accounts, which is the question
            // you ask when investigating.
            Log::warning('login.failed', ['email' => $request->validated('email')]);

            return response()->json(['message' => 'Credenciais inválidas.'], 401);
        }

        $throttle->clear($request);

        Log::info('login.ok', ['user_id' => $user->id]);

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Revokes only this session's token, not all of the user's.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }
}
