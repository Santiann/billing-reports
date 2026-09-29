<?php

/*
 * CORS restricted to the application's frontend.
 *
 * The framework's default is `allowed_origins => ['*']`, and in this design that is unused
 * surface: the browser NEVER calls this API directly. Every read goes through a Server
 * Component, every write through a Server Action and every download through a Route Handler —
 * always from Next's server, where CORS does not apply. What is left is defence in depth for
 * the day someone points a browser client here.
 *
 * `supports_credentials` stays false: authentication is by token in the header, not by a
 * session cookie. Enabling it together with origin `*` is the combination the browser itself
 * refuses, and it is not needed here.
 */

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],

    'allowed_origins_patterns' => [],

    // Only the ones the API actually reads.
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Idempotency-Key',
        'X-Request-Id',
    ],

    // The ones the client needs to be able to read in the response.
    'exposed_headers' => [
        'Idempotent-Replay',
        'Retry-After',
        'X-Request-Id',
    ],

    'max_age' => 0,

    'supports_credentials' => false,

];
