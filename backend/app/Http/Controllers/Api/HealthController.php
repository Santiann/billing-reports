<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Health for monitoring: public, cheap and honest.
 *
 * Honest is the part that matters. A health check that always answers 200 is worse than none,
 * because the monitoring starts trusting it and stops warning. Here, a dependency being down
 * drops the response to 503 and says which one.
 *
 * The cache check is a READ. Writing would prove more, and would cost one commit per probe —
 * with the database driver, every write goes to disk. Monitoring that polls every ten seconds
 * would write 8,640 times a day to answer a question the read already answers: the driver is
 * acessível.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->select('select 1')),
            'cache' => $this->check(fn () => Cache::get('health')),
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /** @return array<string, mixed> */
    private function check(Closure $check): array
    {
        $start = microtime(true);

        try {
            $check();
        } catch (Throwable $error) {
            /*
             * The driver's message stays in the LOG, not in the response.
             *
             * The route is public, and PDO's error names the host, the port and the driver —
             * `SQLSTATE[HY000] [2002] Connection refused`, with the DSN alongside. That is
             * free reconnaissance for whoever is probing. Whoever needs the detail is
             * whoever operates the system, and they have the structured log with the request
             * identifier to find it.
             */
            Log::error('health.failed', ['error' => $error->getMessage()]);

            return ['ok' => false, 'error' => 'did not respond'];
        }

        return ['ok' => true, 'duration_ms' => round((microtime(true) - $start) * 1000, 2)];
    }
}
