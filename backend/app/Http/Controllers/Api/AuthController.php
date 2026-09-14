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
         * O limite é verificado depois da validação, e isso é deliberado:
         * requisição sem e-mail nem senha não é tentativa de autenticação, e
         * contá-la deixaria um cliente com bug de formulário trancar o próprio
         * usuário.
         */
        $segundos = $throttle->bloqueadoPor($request);

        if ($segundos !== null) {
            Log::warning('login.bloqueado', [
                'email' => $request->validated('email'),
                'retry_after' => $segundos,
            ]);

            return response()->json([
                'message' => "Muitas tentativas de login. Tente de novo em {$segundos} segundos.",
            ], 429)->header('Retry-After', (string) $segundos);
        }

        $user = User::where('email', $request->validated('email'))->first();

        // 401 e não 422: o payload é válido, o que falhou foi a autenticação.
        // Mensagem única para e-mail inexistente e senha errada, para não
        // revelar quais e-mails existem.
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            $throttle->registrar($request);

            // O e-mail tentado vai para o log: sem ele não há como distinguir
            // uma pessoa que errou a senha de uma varredura de contas, que é a
            // pergunta que se faz ao investigar.
            Log::warning('login.falhou', ['email' => $request->validated('email')]);

            return response()->json(['message' => 'Credenciais inválidas.'], 401);
        }

        $throttle->limpar($request);

        Log::info('login.ok', ['user_id' => $user->id]);

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Revoga só o token desta sessão, não todos os do usuário.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }
}
