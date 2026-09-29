<?php

namespace App\Domain\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Login attempt limiting, as two separate counts.
 *
 * These are two different attacks, and a single count would let one of them
 * through:
 *
 *   per email + IP  brute force against one account
 *   per IP          sweeping emails, one attempt at each
 *
 * The per-credential limit includes the IP on purpose. Counting by email alone
 * would let anyone lock someone else's account from outside by getting the
 * password wrong five times — denial of service dressed up as security.
 *
 * This lives here rather than in the `throttle` middleware because the
 * controller needs all three operations: ask, count and CLEAR on a successful
 * login. Clearing requires the same key, and the one the middleware uses
 * internally is derived from the limiter name — a framework implementation
 * detail to depend on.
 */
final class LoginThrottle
{
    /** Wrong attempts on the same account, from the same IP. */
    public const PER_CREDENTIAL = 5;

    /**
     * Attempts from the same IP, across every account.
     *
     * Deliberately looser than the other one: an office behind a single IP has
     * several people logging in legitimately, and what this is meant to catch
     * is the sweep, which runs into the dozens.
     */
    public const PER_IP = 20;

    private const WINDOW_SECONDS = 60;

    /** Seconds until the next attempt is allowed, or null when not blocked. */
    public function blockedFor(Request $request): ?int
    {
        foreach ($this->limits($request) as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return RateLimiter::availableIn($key);
            }
        }

        return null;
    }

    public function record(Request $request): void
    {
        foreach (array_keys($this->limits($request)) as $key) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
        }
    }

    /**
     * Clears the credential count — and only that one.
     *
     * A successful login proves that account is not under brute force. It
     * proves nothing about the IP: whoever is sweeping emails may have hit
     * their own.
     */
    public function clear(Request $request): void
    {
        RateLimiter::clear($this->credentialKey($request));
    }

    /** @return array<string, int> */
    private function limits(Request $request): array
    {
        return [
            $this->credentialKey($request) => self::PER_CREDENTIAL,
            'login-ip:'.sha1((string) $request->ip()) => self::PER_IP,
        ];
    }

    private function credentialKey(Request $request): string
    {
        $email = mb_strtolower(trim((string) $request->input('email')));

        return 'login:'.sha1($email.'|'.$request->ip());
    }
}
