<?php

namespace App\Http\Middleware;

use App\Domain\Idempotency\IdempotencyOutcome;
use App\Domain\Idempotency\IdempotencyStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a request repeatable when it carries `Idempotency-Key`.
 *
 * Middleware, and not code in the controller, for a reason the test makes visible: the
 * validation response has to be replayed too, and the 422 is born in the FormRequest,
 * before the controller exists. Only from out here can you store what the route
 * answered, regardless of who answered it.
 *
 * The header is optional. Without it nothing changes — including the refusal to pay an
 * already paid billing, which stays a 422. Idempotency serves whoever REPEATS the same
 * operation; turning the second attempt into a success for everyone would hide a real
 * error.
 */
class IdempotentRequest
{
    public function __construct(private readonly IdempotencyStore $store) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header(IdempotencyStore::HEADER, ''));
        $user = $request->user();

        // No key, or no user — the second case belongs to `auth`, which runs before and
        // answers 401; arriving here with no user would mean middleware out of order, and
        // inventing a response here would hide that.
        if ($key === '' || $user === null) {
            return $next($request);
        }

        if (mb_strlen($key) > 255) {
            return $this->refuse(
                'A chave de idempotência passa de 255 caracteres.',
                422,
            );
        }

        $fingerprint = $this->fingerprint($request);
        $reserve = $this->store->reserve($user->id, $key, $fingerprint);

        return match ($reserve->outcome) {
            IdempotencyOutcome::Reserved => $this->process($request, $next, $user->id, $key, $fingerprint),

            IdempotencyOutcome::Replayed => $this->replay($reserve->status, $reserve->body),

            IdempotencyOutcome::Conflict => $this->refuse(
                'Esta chave de idempotência já foi usada para outra requisição.',
                422,
            ),

            IdempotencyOutcome::InFlight => $this->refuse(
                'Uma requisição com esta chave de idempotência ainda está em andamento.',
                409,
            ),
        };
    }

    private function process(Request $request, Closure $next, int $userId, string $key, string $fingerprint): Response
    {
        $response = $next($request);

        /*
         * A server error gives the key back.
         *
         * A 500 is not the operation's result, it is a failure to produce one — and the
         * right move after a 500 is to try again. Storing it would make the key replay
         * the failure for the next 24 hours, which is the opposite of what it exists to
         * do.
         */
        if ($response->getStatusCode() >= 500) {
            $this->store->release($userId, $key);

            return $response;
        }

        /*
         * A streamed response has no body to store: the content only exists while it is
         * being sent, and reading it here would undo the streaming. No idempotent route
         * exports a file today; the guard exists for the day someone applies this
         * middleware to one that does.
         */
        if ($response->getContent() === false) {
            $this->store->release($userId, $key);

            return $response;
        }

        $this->store->store(
            $userId,
            $key,
            $fingerprint,
            $response->getStatusCode(),
            $response->getContent(),
        );

        return $response;
    }

    /**
     * The stored response, returned exactly as it came.
     *
     * The `Idempotent-Replay` header signals that this is a replay. The caller does not
     * need it to work — the body is identical — but does need it to tell "just paid"
     * from "had already paid" in the log.
     */
    private function replay(int $status, string $body): Response
    {
        return response($body, $status)
            ->header('Content-Type', 'application/json')
            ->header('Idempotent-Replay', 'true');
    }

    private function refuse(string $message, int $status): Response
    {
        return response()->json(['message' => $message], $status);
    }

    /**
     * What identifies the REQUEST, to tell a replay from a reuse.
     *
     * It takes in the method, the path — with the billing's id inside it, so the same key
     * on another billing is another request — and the submitted data, sorted so the order
     * of the JSON's keys does not change the fingerprint.
     */
    private function fingerprint(Request $request): string
    {
        return hash('sha256', (string) json_encode([
            $request->method(),
            $request->path(),
            $this->sort($request->all()),
        ]));
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function sort(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sort($value);
            }
        }

        return $data;
    }
}
