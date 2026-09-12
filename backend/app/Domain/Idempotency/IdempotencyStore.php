<?php

namespace App\Domain\Idempotency;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Lottery;

/**
 * Reserva chaves de idempotência e guarda o resultado de cada uma.
 *
 * A mecânica inteira mora aqui; o middleware só traduz para HTTP. A separação
 * paga na hora de testar — e na hora de a segunda operação idempotente
 * aparecer, que vai querer a mesma mecânica e outro transporte.
 */
final class IdempotencyStore
{
    /** O nome vem do rascunho da IETF para o cabeçalho (draft-ietf-httpapi-idempotency-key-header). */
    public const HEADER = 'Idempotency-Key';

    private const TABLE = 'idempotency_keys';

    /**
     * Quanto tempo uma chave vale.
     *
     * Guardar para sempre não é opção — a tabela cresceria sem teto, e uma
     * chave de meses atrás repetiria uma resposta que já não descreve o
     * registro. Vinte e quatro horas cobre com folga o que a idempotência
     * existe para cobrir: duplo clique, retry de rede, reenvio de formulário.
     */
    private const TTL_HOURS = 24;

    /**
     * Tenta tomar a chave para esta requisição.
     *
     * O INSERT vem primeiro de propósito. Consultar antes e inserir depois
     * deixaria uma janela entre as duas consultas em que duas requisições
     * simultâneas passariam as duas — que é exatamente o caso do duplo clique.
     * Aqui o índice único arbitra, e quem perde a corrida cai no `catch` e
     * descobre pelo estado da linha o que fazer.
     */
    public function reserve(int $userId, string $key, string $fingerprint): IdempotencyReservation
    {
        $agora = CarbonImmutable::now();

        try {
            DB::table(self::TABLE)->insert([
                'user_id' => $userId,
                'key' => $key,
                'fingerprint' => $fingerprint,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            $this->limparVencidas();

            return new IdempotencyReservation(IdempotencyOutcome::Reserved);
        } catch (UniqueConstraintViolationException) {
            // A chave já é de alguma requisição. Qual delas, o estado da linha diz.
        }

        $registro = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('key', $key)
            ->first();

        /*
         * A linha existia no INSERT e não existe mais: venceu e foi limpa entre
         * as duas consultas. Nada está reservado, então a requisição segue — o
         * `store()` recria a linha no fim.
         */
        if ($registro === null) {
            return new IdempotencyReservation(IdempotencyOutcome::Reserved);
        }

        /*
         * Chave vencida é chave nova.
         *
         * Reaproveita-se a linha em vez de apagar e inserir: é um UPDATE só, e
         * apagar abriria espaço para uma terceira requisição inserir no meio.
         * Vale também para a linha em voo que venceu — uma requisição que
         * morreu sem gravar resposta não pode travar a chave para sempre.
         */
        if (CarbonImmutable::parse($registro->created_at)->addHours(self::TTL_HOURS)->isPast()) {
            DB::table(self::TABLE)->where('id', $registro->id)->update([
                'fingerprint' => $fingerprint,
                'response_status' => null,
                'response_body' => null,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            return new IdempotencyReservation(IdempotencyOutcome::Reserved);
        }

        /*
         * Reservada e ainda sem resposta: a primeira requisição está em voo.
         *
         * Vem antes da comparação de impressão digital porque ainda não há o
         * que comparar contra nem o que devolver. A resposta honesta é "tente
         * de novo daqui a pouco", e é o 409 que impede as duas de processarem.
         */
        if ($registro->response_status === null) {
            return new IdempotencyReservation(IdempotencyOutcome::InFlight);
        }

        if (! hash_equals($registro->fingerprint, $fingerprint)) {
            return new IdempotencyReservation(IdempotencyOutcome::Conflict);
        }

        return new IdempotencyReservation(
            IdempotencyOutcome::Replayed,
            (int) $registro->response_status,
            (string) $registro->response_body,
        );
    }

    /**
     * Grava o resultado para que a próxima chamada com a mesma chave o receba.
     *
     * `updateOrInsert` e não `update` por causa do caso raro lá de cima: se a
     * linha reservada tiver sido limpa por vencimento durante o processamento,
     * ainda assim o resultado precisa ficar guardado.
     */
    public function store(int $userId, string $key, string $fingerprint, int $status, string $body): void
    {
        $agora = CarbonImmutable::now();

        DB::table(self::TABLE)->updateOrInsert(
            ['user_id' => $userId, 'key' => $key],
            [
                'fingerprint' => $fingerprint,
                'response_status' => $status,
                'response_body' => $body,
                'created_at' => $agora,
                'updated_at' => $agora,
            ],
        );
    }

    /**
     * Devolve a chave para quem a usou.
     *
     * Serve ao erro de servidor: um 500 não é resultado, é falha. Guardá-lo
     * condenaria a chave a repetir a falha por 24 horas, quando repetir a
     * requisição é justamente o que o cliente deve fazer.
     */
    public function release(int $userId, string $key): void
    {
        DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('key', $key)
            ->delete();
    }

    /**
     * Apaga as chaves vencidas, de vez em quando.
     *
     * Por sorteio, e não a cada requisição, porque a limpeza é manutenção e não
     * pode custar um DELETE em toda operação de escrita. É a mesma estratégia
     * que o Laravel usa para expirar sessão em arquivo.
     *
     * Não é tarefa agendada porque este projeto não sobe worker: agendar seria
     * escrever uma limpeza que nunca roda.
     */
    private function limparVencidas(): void
    {
        Lottery::odds(1, 200)->winner(function (): void {
            DB::table(self::TABLE)
                ->where('created_at', '<', CarbonImmutable::now()->subHours(self::TTL_HOURS))
                ->delete();
        })->choose();
    }
}
