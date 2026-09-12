<?php

namespace App\Domain\Idempotency;

/**
 * Os quatro desfechos possíveis de uma reserva de chave.
 *
 * São quatro porque "a chave já existe" não é uma situação só, e tratá-la como
 * uma seria o erro do desenho: repetir a mesma requisição, reaproveitar a
 * chave para outra, e chegar enquanto a primeira ainda processa pedem respostas
 * diferentes.
 */
enum IdempotencyOutcome
{
    /** Chave nova: a requisição segue e o resultado será guardado. */
    case Reserved;

    /** Mesma chave, mesmo pedido: devolve-se o resultado guardado. */
    case Replayed;

    /** Mesma chave, pedido diferente: é bug de quem chama. */
    case Conflict;

    /** Mesma chave, primeira requisição ainda em voo. */
    case InFlight;
}
