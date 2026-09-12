<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice de COBERTURA para o dashboard.
 *
 * Os sete índices do relatório apontam para a linha; este carrega os valores
 * dentro de si. É a diferença entre o MySQL varrer a faixa de datas no índice e
 * ainda buscar cada linha no disco para somar, ou responder sem tocar na tabela
 * — o `Using index` do EXPLAIN é o nome disso.
 *
 * Medido contra 2.000.000 de cobranças, nas duas consultas do dashboard:
 *
 *                                        sem cobertura   com este índice
 *   série de 12 meses (12 faixas)             8,25s           0,31s
 *   indicadores do mês (com juros)            0,72s           0,07s
 *
 * As cinco colunas não são exagero, são exatamente o que as duas consultas
 * leem. Tirar `monthly_interest_rate` sozinho já obriga o cálculo de juros a
 * voltar à tabela linha por linha, e os indicadores saem de 0,07s para 0,72s.
 *
 * A ordem também não é livre: `due_date` primeiro porque é o range que recorta,
 * e o resto depois, porque só precisa estar presente para ser lido. Coluna de
 * igualdade antes de range vale quando há igualdade — aqui não há.
 *
 * O custo é conhecido e aceito: 79 MB e mais uma árvore para manter a cada
 * insert. A carga do seeder já paga 4,8x de penalidade por causa dos sete
 * índices do relatório, e este é o oitavo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            //   WHERE due_date >= ? AND due_date < ?
            //   -> COUNT, SUM(original_amount), SUM(paid_amount),
            //      e o cálculo de juros sobre as pendentes
            $table->index(
                [
                    'due_date',
                    'status',
                    'monthly_interest_rate',
                    'original_amount',
                    'paid_amount',
                ],
                'billings_dashboard_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropIndex('billings_dashboard_index');
        });
    }
};
