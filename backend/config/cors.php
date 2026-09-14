<?php

/*
 * CORS restrito ao frontend da aplicação.
 *
 * O default do framework é `allowed_origins => ['*']`, e neste desenho isso é
 * superfície sem uso: o browser NUNCA chama esta API direto. Toda leitura passa
 * por Server Component, toda escrita por Server Action e todo download por
 * Route Handler — sempre do servidor do Next, onde CORS não se aplica. O que
 * sobra é defesa em profundidade para o dia em que alguém apontar um cliente de
 * browser para cá.
 *
 * `supports_credentials` fica falso: a autenticação é por token no cabeçalho, e
 * não por cookie de sessão. Habilitá-lo junto com origem `*` é a combinação que
 * o próprio navegador recusa, e não é necessária aqui.
 */

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],

    'allowed_origins_patterns' => [],

    // Só os que a API realmente lê.
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Idempotency-Key',
        'X-Request-Id',
    ],

    // Os que o cliente precisa conseguir ler na resposta.
    'exposed_headers' => [
        'Idempotent-Replay',
        'Retry-After',
        'X-Request-Id',
    ],

    'max_age' => 0,

    'supports_credentials' => false,

];
