<?php

namespace Database\Seeders;

use App\Domain\Billing\BillingStatus;
use App\Domain\Customer\CustomerStatus;
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
 */
class BillingVolumeSeeder extends Seeder
{
    private const CUSTOMERS = 5_000;

    /** Linhas por INSERT. Acima disso o max_allowed_packet começa a apertar. */
    private const CHUNK = 2_000;

    public function run(): void
    {
        $total = (int) (env('BILLING_SEED_COUNT') ?: 2_000_000);

        // Sem isso o Laravel guarda cada INSERT em memória e o processo morre
        // por exaustão muito antes do fim.
        DB::connection()->disableQueryLog();

        $this->command->info(sprintf(
            'Gerando %s clientes e %s cobranças…',
            number_format(self::CUSTOMERS, 0, ',', '.'),
            number_format($total, 0, ',', '.'),
        ));

        $startedAt = microtime(true);

        $this->truncate();
        $this->seedCustomers();
        $customerIds = DB::table('customers')->pluck('id')->all();

        $this->seedBillings($total, $customerIds);

        $this->command->info(sprintf(
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
        Schema::disableForeignKeyConstraints();
        DB::table('billings')->truncate();
        DB::table('customers')->truncate();
        Schema::enableForeignKeyConstraints();
    }

    private function seedCustomers(): void
    {
        $now = now();
        $rows = [];

        for ($i = 1; $i <= self::CUSTOMERS; $i++) {
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

        $rows = [];
        $inserted = 0;

        for ($i = 0; $i < $total; $i++) {
            // Emissão espalhada por três anos para o filtro de período ter o
            // que recortar.
            $issueDate = $today->copy()->subDays(mt_rand(0, 1_095));
            $dueDate = $issueDate->copy()->addDays(30);
            $amount = mt_rand(10_000, 1_000_000) / 100;

            $isPaid = mt_rand(1, 100) <= 40;

            $rows[] = [
                'customer_id' => $customerIds[mt_rand(0, $lastCustomer)],
                'description' => $descriptions[mt_rand(0, 5)],
                'original_amount' => $amount,
                'monthly_interest_rate' => $rates[mt_rand(0, 3)],
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                // Pagas são todas em dia: juros zero não depende da regra de
                // cálculo, então este seeder não precisa repeti-la. As pagas
                // em atraso entram junto com o InterestCalculator.
                'payment_date' => $isPaid ? $dueDate->copy()->subDays(mt_rand(1, 25))->toDateString() : null,
                'status' => $isPaid ? BillingStatus::Paid->value : BillingStatus::Pending->value,
                'paid_amount' => $isPaid ? $amount : null,
                'paid_interest_amount' => $isPaid ? '0.00' : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= self::CHUNK) {
                DB::table('billings')->insert($rows);
                $inserted += count($rows);
                $rows = [];

                if ($inserted % 100_000 === 0) {
                    $this->command->info(sprintf(
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

    private function humanize(float $seconds): string
    {
        return $seconds < 60
            ? sprintf('%.1fs', $seconds)
            : sprintf('%dmin %ds', (int) ($seconds / 60), (int) $seconds % 60);
    }
}
