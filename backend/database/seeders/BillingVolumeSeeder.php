<?php

namespace Database\Seeders;

use App\Domain\Billing\BillingStatus;
use App\Domain\Billing\RegisterPayment;
use App\Domain\Customer\CustomerStatus;
use App\Models\Billing;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gera volume real para medir o relatório.
 *
 * Não roda no DatabaseSeeder: são milhões de linhas e vários minutos. Invocar
 * explicitamente:
 *
 *     docker compose exec php php artisan db:seed --class=BillingVolumeSeeder
 *
 * O total é configurável por env para permitir uma amostra menor:
 *
 *     docker compose exec -e BILLING_SEED_COUNT=100000 php \
 *         php artisan db:seed --class=BillingVolumeSeeder
 *
 * Insert em lote, nunca factory registro a registro: a factory instancia um
 * model, dispara eventos e faz um INSERT por linha. Em dois milhões de
 * cobranças a diferença não é de porcentagem, é de ordem de grandeza.
 *
 * Parte das pagas é paga COM ATRASO, com juros congelados de verdade. Sem
 * isso a base de medição não exercita a regra de congelamento: o relatório
 * mostraria zero de juros recebidos e a tela de detalhe nunca teria o que
 * exibir. Quem calcula o valor congelado é o RegisterPayment, o mesmo serviço
 * da API — o insert em lote continua, o que muda é de onde vem o número.
 */
class BillingVolumeSeeder extends Seeder
{
    private const CUSTOMERS = 5_000;

    /** Proporção das cobranças que nascem pagas. */
    private const PAID_PERCENT = 40;

    /** Proporção DAS PAGAS que foram pagas com atraso, e portanto com juros. */
    private const PAID_LATE_PERCENT = 35;

    /**
     * Teto de atraso do pagamento, em dias.
     *
     * Sem teto, uma cobrança vencida há três anos e paga hoje a 5% ao mês
     * acumularia 1,05^36 — quase seis vezes o valor original. Existe, mas não
     * é o que uma base de faturamento parece.
     */
    private const MAX_DAYS_LATE = 120;

    /** Antecedência máxima de um pagamento em dia, em dias. */
    private const MAX_DAYS_EARLY = 25;

    /** Linhas por INSERT. Acima disso o max_allowed_packet começa a apertar. */
    private const CHUNK = 2_000;

    /**
     * O total também entra por construtor, e não só por env, para o teste
     * conseguir semear uma amostra pequena: sobrescrever `env()` de dentro do
     * teste mexeria no ambiente do processo inteiro.
     */
    public function __construct(private readonly ?int $total = null) {}

    public function run(): void
    {
        $total = $this->total ?? (int) (env('BILLING_SEED_COUNT') ?: 2_000_000);

        // Uma amostra de 600 cobranças espalhada por 5.000 clientes não se
        // parece com nada: quase todo cliente ficaria com zero ou uma
        // cobrança. A partir de 100.000 o teto vale e a carga real não muda.
        $customers = max(1, min(self::CUSTOMERS, intdiv($total, 20)));

        // Sem isso o Laravel guarda cada INSERT em memória e o processo morre
        // por exaustão muito antes do fim.
        DB::connection()->disableQueryLog();

        $this->command?->info(sprintf(
            'Gerando %s clientes e %s cobranças…',
            number_format($customers, 0, ',', '.'),
            number_format($total, 0, ',', '.'),
        ));

        $startedAt = microtime(true);

        $this->truncate();
        $this->seedCustomers($customers);
        $customerIds = DB::table('customers')->pluck('id')->all();

        $this->seedBillings($total, $customerIds);

        $this->command?->info(sprintf(
            'Concluído em %s.',
            $this->humanize(microtime(true) - $startedAt),
        ));
    }

    /**
     * Começa do zero a cada execução.
     *
     * Os documentos são sequenciais para garantir unicidade sem consultar o
     * banco, o que torna uma segunda execução impossível sobre os dados da
     * primeira. E medir consulta sobre volume acumulado de execuções
     * anteriores não diria nada — o ponto é o volume ser conhecido.
     */
    private function truncate(): void
    {
        // TRUNCATE é DDL e custa segundos mesmo sobre tabela vazia. Sair cedo
        // quando não há o que limpar tira esse custo de cada teste da suíte —
        // e, de quebra, preserva a transação do RefreshDatabase, que um
        // TRUNCATE encerraria por commit implícito.
        if (! DB::table('billings')->exists() && ! DB::table('customers')->exists()) {
            return;
        }

        Schema::disableForeignKeyConstraints();
        // A trilha vai junto: o TRUNCATE reinicia os ids das cobranças, e a
        // trilha antiga passaria a descrever cobranças que não são as dela.
        DB::table('billing_audits')->truncate();
        DB::table('billings')->truncate();
        DB::table('customers')->truncate();
        Schema::enableForeignKeyConstraints();
    }

    private function seedCustomers(int $customers): void
    {
        $now = now();
        $rows = [];

        for ($i = 1; $i <= $customers; $i++) {
            $rows[] = [
                'name' => "Cliente {$i}",
                // Sequencial e não aleatório: garante unicidade sem colisão e
                // sem precisar consultar o banco a cada linha.
                'document' => str_pad((string) $i, 11, '0', STR_PAD_LEFT),
                'email' => "cliente{$i}@exemplo.test",
                'status' => $i % 20 === 0
                    ? CustomerStatus::Inactive->value
                    : CustomerStatus::Active->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= self::CHUNK) {
                DB::table('customers')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('customers')->insert($rows);
        }
    }

    /**
     * @param  array<int, int>  $customerIds
     */
    private function seedBillings(int $total, array $customerIds): void
    {
        $now = now();
        $today = now()->startOfDay();
        $lastCustomer = count($customerIds) - 1;

        // Faker é lento demais para milhões de linhas: um sorteio num pool
        // pequeno gera dados suficientes para medir consulta e índice.
        $descriptions = [
            'Mensalidade', 'Serviço prestado', 'Licença de uso',
            'Consultoria', 'Suporte técnico', 'Hospedagem',
        ];
        $rates = ['0.0100', '0.0200', '0.0350', '0.0500'];

        $registerPayment = app(RegisterPayment::class);

        $rows = [];
        $inserted = 0;

        for ($i = 0; $i < $total; $i++) {
            // Emissão espalhada por três anos para o filtro de período ter o
            // que recortar.
            $issueDate = $today->copy()->subDays(mt_rand(0, 1_095));
            $dueDate = $issueDate->copy()->addDays(30);
            $amount = mt_rand(10_000, 1_000_000) / 100;
            $rate = $rates[mt_rand(0, 3)];

            $payment = mt_rand(1, 100) <= self::PAID_PERCENT
                ? $this->freezePayment($registerPayment, $amount, $rate, $dueDate, $today)
                : null;

            $rows[] = [
                'customer_id' => $customerIds[mt_rand(0, $lastCustomer)],
                'description' => $descriptions[mt_rand(0, 5)],
                'original_amount' => $amount,
                'monthly_interest_rate' => $rate,
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'payment_date' => $payment['payment_date'] ?? null,
                'status' => $payment['status'] ?? BillingStatus::Pending->value,
                'paid_amount' => $payment['paid_amount'] ?? null,
                'paid_interest_amount' => $payment['paid_interest_amount'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= self::CHUNK) {
                DB::table('billings')->insert($rows);
                $inserted += count($rows);
                $rows = [];

                if ($inserted % 100_000 === 0) {
                    $this->command?->info(sprintf(
                        '  %s / %s',
                        number_format($inserted, 0, ',', '.'),
                        number_format($total, 0, ',', '.'),
                    ));
                }
            }
        }

        if ($rows !== []) {
            DB::table('billings')->insert($rows);
        }
    }

    /**
     * Sorteia QUANDO a cobrança foi paga e devolve as colunas congeladas.
     *
     * Quem calcula o valor é o RegisterPayment, o mesmo serviço que a API usa.
     * Este método decide a data e nada além disso: repetir a fórmula de juros
     * aqui faria do seeder uma segunda implementação da regra, e a base de
     * medição deixaria de valer como prova do que a tela mostra.
     *
     * Devolve null quando não existe data de pagamento possível — cobrança que
     * vence daqui a mais de MAX_DAYS_EARLY dias ainda não foi paga, porque o
     * pagamento cairia no futuro.
     *
     * @return array<string, string>|null
     */
    private function freezePayment(
        RegisterPayment $registerPayment,
        float $amount,
        string $rate,
        CarbonInterface $dueDate,
        CarbonInterface $today,
    ): ?array {
        $daysOverdue = $dueDate->lt($today) ? (int) $dueDate->diffInDays($today) : 0;

        if ($daysOverdue > 0 && mt_rand(1, 100) <= self::PAID_LATE_PERCENT) {
            $paymentDate = $dueDate->copy()->addDays(
                mt_rand(1, min(self::MAX_DAYS_LATE, $daysOverdue)),
            );
        } else {
            // Paga em dia. O piso da antecedência é o que ainda falta para
            // vencer: sem ele, uma cobrança que vence semana que vem seria
            // paga depois de hoje.
            $daysEarly = $daysOverdue > 0 ? 1 : (int) $today->diffInDays($dueDate);

            if ($daysEarly > self::MAX_DAYS_EARLY) {
                return null;
            }

            $paymentDate = $dueDate->copy()->subDays(
                mt_rand($daysEarly, self::MAX_DAYS_EARLY),
            );
        }

        // Model não persistido: serve só para o calculador ler valor, taxa e
        // vencimento. Persistir aqui seria voltar ao INSERT por linha.
        return $registerPayment->freeze(
            new Billing([
                'original_amount' => $amount,
                'monthly_interest_rate' => $rate,
                'due_date' => $dueDate->toDateString(),
            ]),
            $paymentDate,
        );
    }

    private function humanize(float $seconds): string
    {
        return $seconds < 60
            ? sprintf('%.1fs', $seconds)
            : sprintf('%dmin %ds', (int) ($seconds / 60), (int) $seconds % 60);
    }
}
