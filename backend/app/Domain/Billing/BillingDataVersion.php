<?php

namespace App\Domain\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Um número que muda sempre que os dados das cobranças mudam.
 *
 * O cache dos totalizadores guarda junto dos totais a versão com que eles
 * foram calculados, e só os serve se a versão ainda for a corrente.
 *
 * O `bump()` precisa rodar DENTRO da transação da escrita. Aí a linha fica
 * travada até o commit, duas escritas simultâneas sobem o número em fila, e os
 * dados novos e a versão nova ficam visíveis no mesmo instante. Fora da
 * transação haveria um intervalo entre o commit dos dados e o da versão em que
 * o cache serviria o total de antes.
 *
 * O preço é justamente essa fila: toda escrita em cobrança passa por esta
 * linha. Para pagamentos feitos por pessoas é imperceptível; o README registra
 * a troca.
 */
final class BillingDataVersion
{
    private const TABLE = 'billing_data_versions';

    public function current(): int
    {
        return (int) DB::table(self::TABLE)->where('id', 1)->value('version');
    }

    public function bump(): void
    {
        DB::table(self::TABLE)->where('id', 1)->increment('version');
    }
}
