<?php

namespace App\Domain\Dashboard;

use App\Domain\Billing\BillingStatus;
use App\Domain\Billing\InterestCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * As duas consultas do dashboard.
 *
 * Tudo agrega no banco. Nenhuma delas traz linha para o PHP somar — sobre dois
 * milhões de cobranças isso não seria lento, seria impossível.
 *
 * Os juros saem do InterestCalculator, como em todo o resto: o dashboard não
 * pode discordar do relatório sobre o mesmo recorte, e a única forma de
 * garantir isso é não ter uma segunda fórmula.
 */
final class DashboardQuery
{
    /** Meses exibidos na série, incluindo o corrente. */
    private const MESES = 12;

    private readonly CarbonImmutable $hoje;

    private readonly InterestCalculator $calculator;

    public function __construct(CarbonInterface|string|null $hoje = null)
    {
        $this->hoje = $hoje === null
            ? CarbonImmutable::now()->startOfDay()
            : CarbonImmutable::parse($hoje)->startOfDay();

        $this->calculator = new InterestCalculator($this->hoje);
    }

    /**
     * Indicadores do mês corrente, recortado por VENCIMENTO.
     *
     * Vencimento e não emissão: o que interessa a quem abre o sistema é o que
     * vence agora — o que entrou, o que falta entrar e o que já venceu.
     *
     * @return array<string, mixed>
     */
    public function period(): array
    {
        $inicio = $this->hoje->startOfMonth();
        $fim = $inicio->addMonth();

        $pendente = BillingStatus::Pending->value;
        $atualizado = $this->calculator->updatedAmountSql();

        /*
         * O valor atualizado é calculado UMA vez por linha, numa derivada, e os
         * juros saem dele por subtração.
         *
         * A versão direta somava `updatedAmountSql()` e `interestAmountSql()`
         * lado a lado, e as duas carregam o mesmo POW — o MySQL o executava
         * duas vezes em cada uma das 55.000 linhas do mês. Medido: 0,87s
         * contra 0,45s.
         *
         * A subtração vale porque a soma é só sobre PENDENTE, e para pendente
         * juros é exatamente valor atualizado menos original. Em cobrança paga
         * não valeria — lá os juros são a coluna congelada — e por isso ela
         * entra com zero.
         */
        $linha = DB::table(DB::raw('('
            .'SELECT original_amount, paid_amount,'
            ." CASE WHEN status = '{$pendente}' THEN {$atualizado} ELSE NULL END AS a_receber,"
            ." {$this->calculator->overdueSql()} AS vencida"
            .' FROM billings'
            .' WHERE due_date >= ? AND due_date < ?'
            .') AS mes'))
            ->setBindings([$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw(
                'COUNT(*) AS total,'
                .' COALESCE(SUM(original_amount), 0) AS original,'
                // Recebido sai da coluna congelada, nunca de recálculo.
                .' COALESCE(SUM(paid_amount), 0) AS recebido,'
                .' COALESCE(SUM(a_receber), 0) AS a_receber,'
                .' COALESCE(SUM(a_receber - original_amount), 0) AS juros,'
                .' COALESCE(SUM(vencida), 0) AS vencidas',
            )
            ->first();

        return [
            'label' => $this->rotuloDoMes($inicio),
            'start_date' => $inicio->toDateString(),
            'end_date' => $fim->subDay()->toDateString(),
            'count' => (int) $linha->total,
            'original_amount' => $this->money($linha->original),
            'received_amount' => $this->money($linha->recebido),
            'pending_amount' => $this->money($linha->a_receber),
            'interest_amount' => $this->money($linha->juros),
            'overdue_count' => (int) $linha->vencidas,
        ];
    }

    /**
     * Faturado e recebido nos últimos doze meses, por vencimento.
     *
     * Doze faixas estreitas unidas por UNION ALL, e não um GROUP BY sobre o ano
     * inteiro. A diferença não é de estilo, é de ordem de grandeza — medido
     * contra 2.000.000 de cobranças:
     *
     *     GROUP BY DATE_FORMAT(due_date, '%Y-%m')  ->  1,75s
     *     doze faixas em UNION ALL                 ->  0,33s
     *
     * O motivo está no EXPLAIN: a função sobre a coluna impede o MySQL de
     * agrupar na ordem do índice, e ele monta tabela temporária com o ano
     * inteiro (`Using temporary`). Cada faixa isolada é um range simples que o
     * índice de cobertura responde sem tocar na tabela.
     *
     * `SUM(paid_amount)` soma direto, sem filtrar por status, porque valor pago
     * só existe em cobrança paga — as duas formas dão o mesmo número e esta é
     * mais curta. A equivalência não é suposição: há teste afirmando, nos dois
     * sentidos, que nenhuma linha tem valor pago sem estar paga nem o
     * contrário.
     *
     * @return array<int, array<string, mixed>>
     */
    public function monthly(): array
    {
        $primeiro = $this->hoje->startOfMonth()->subMonths(self::MESES - 1);

        $partes = [];
        $valores = [];

        for ($i = 0; $i < self::MESES; $i++) {
            $mes = $primeiro->addMonths($i);

            $partes[] = 'SELECT ? AS mes, COUNT(*) AS total,'
                .' COALESCE(SUM(original_amount), 0) AS original,'
                .' COALESCE(SUM(paid_amount), 0) AS recebido'
                .' FROM billings WHERE due_date >= ? AND due_date < ?';

            $valores[] = $mes->format('Y-m');
            $valores[] = $mes->toDateString();
            $valores[] = $mes->addMonth()->toDateString();
        }

        $linhas = DB::select(implode(' UNION ALL ', $partes), $valores);

        return array_map(fn (object $linha) => [
            'month' => $linha->mes,
            'label' => $this->rotuloCurto($linha->mes),
            'count' => (int) $linha->total,
            'original_amount' => $this->money($linha->original),
            'received_amount' => $this->money($linha->recebido),
        ], $linhas);
    }

    private function rotuloDoMes(CarbonImmutable $mes): string
    {
        $meses = [
            1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
            'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
        ];

        return $meses[$mes->month].' de '.$mes->year;
    }

    /** "2026-09" vira "set/26", que é o que cabe embaixo de uma barra. */
    private function rotuloCurto(string $mes): string
    {
        $curtos = [
            1 => 'jan', 'fev', 'mar', 'abr', 'mai', 'jun',
            'jul', 'ago', 'set', 'out', 'nov', 'dez',
        ];

        [$ano, $numero] = explode('-', $mes);

        return $curtos[(int) $numero].'/'.substr($ano, 2);
    }

    private function money(mixed $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }
}
