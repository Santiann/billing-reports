<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        /*
         * The API's general limit, on top of the login-specific one.
         *
         * The reason is not brute force — it is cost. The report aggregates the whole filtered
         * set, and an uncached query over a one-year scope takes twelve seconds of database
         * time. With no cap, a client in a loop brings the service down for everyone using
         * legitimate credentials.
         *
         * The count is per USER when there is one, and only falls back to the IP for the public
         * routes. Always counting by IP would be wrong here: the frontend calls the API through
         * Next's server, so every one of the application's requests arrives from the same
         * address and one active user would limit the others.
         */
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(180)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
