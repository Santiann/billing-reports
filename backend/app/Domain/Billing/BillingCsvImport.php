<?php

namespace App\Domain\Billing;

use App\Domain\Import\CsvReader;
use App\Domain\Import\ImportReport;
use App\Models\Customer;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Importa cobranças de um CSV.
 *
 * Mesma estrutura da importação de clientes — leitura em gerador, validação por
 * linha, insert em lote, relatório com a linha e o motivo — com duas regras
 * próprias:
 *
 * **O cliente é resolvido por DOCUMENTO.** O arquivo vem de fora e não conhece
 * o id interno; documento é a identidade de negócio que as duas pontas têm. A
 * resolução acontece por lote, numa consulta que traz os clientes dos 500
 * documentos de uma vez — resolver linha a linha transformaria um arquivo de
 * dez mil cobranças em dez mil consultas.
 *
 * **A cobrança nasce pendente.** Status e valores de pagamento não são lidos do
 * arquivo nem que estejam lá. Aceitar `status = paid` criaria cobrança paga sem
 * os valores congelados, que é exatamente o que o formulário de cadastro também
 * recusa — quem faz essa transição é o registro de pagamento.
 */
final class BillingCsvImport
{
    private const BATCH = 500;

    public function __construct(
        private readonly BillingDataVersion $version = new BillingDataVersion(),
    ) {}

    private const COLUMNS = [
        'document' => ['documento', 'document', 'cliente', 'cpf', 'cnpj', 'cpfcnpj'],
        'description' => ['descricao', 'description', 'historico', 'referencia'],
        'original_amount' => ['valor', 'amount', 'valororiginal', 'originalamount'],
        'monthly_interest_rate' => ['taxa', 'juros', 'rate', 'taxamensal', 'monthlyinterestrate'],
        'issue_date' => ['emissao', 'dataemissao', 'issuedate'],
        'due_date' => ['vencimento', 'datavencimento', 'duedate'],
    ];

    public function preview(string $path): ImportReport
    {
        return $this->process($path, store: false);
    }

    public function import(string $path): ImportReport
    {
        return $this->process($path, store: true);
    }

    private function process(string $path, bool $store): ImportReport
    {
        $reader = new CsvReader(self::COLUMNS, [
            'document', 'description', 'original_amount', 'issue_date', 'due_date',
        ]);

        $report = new ImportReport();
        $batch = [];

        foreach ($reader->rows($path) as [$row, $values]) {
            $report->totalRows++;

            $normalized = $this->normalize($values);
            $errors = $this->validate($normalized);

            if ($errors !== []) {
                $report->addError($row, $errors, $values);

                continue;
            }

            // A linha só é contada como válida depois que o cliente é
            // encontrado, e isso acontece no fechamento do lote.
            $batch[$row] = $normalized;

            if (count($batch) >= self::BATCH) {
                $this->flush($batch, $report, $store);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->flush($batch, $report, $store);
        }

        return $report;
    }

    /**
     * Fecha um lote: resolve os clientes e grava o que sobrou.
     *
     * @param  array<int, array<string, string>>  $batch  linha => valores
     */
    private function flush(array $batch, ImportReport $report, bool $store): void
    {
        $customers = Customer::query()
            ->whereIn('document', array_unique(array_column($batch, 'document')))
            ->pluck('id', 'document');

        $now = now();
        $insert = [];

        foreach ($batch as $row => $values) {
            $customerId = $customers[$values['document']] ?? null;

            if ($customerId === null) {
                $report->addError(
                    $row,
                    ['Nenhum cliente cadastrado com este documento.'],
                    // As mesmas chaves que um erro de validação devolve: a tela
                    // exibe o registro por uma delas, e trocar o nome do campo
                    // aqui faria metade dos erros aparecer sem identificação.
                    ['document' => $values['document'], 'description' => $values['description']],
                );

                continue;
            }

            $report->validCount++;

            $billing = [
                'customer_id' => $customerId,
                'description' => $values['description'],
                'original_amount' => $values['original_amount'],
                'monthly_interest_rate' => $values['monthly_interest_rate'],
                'issue_date' => $values['issue_date'],
                'due_date' => $values['due_date'],
                // Nasce pendente, sem exceção. O arquivo não decide isto.
                'status' => BillingStatus::Pending->value,
                'payment_date' => null,
                'paid_amount' => null,
                'paid_interest_amount' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $report->addSample([
                'document' => $values['document'],
                'description' => $values['description'],
                'original_amount' => $values['original_amount'],
                'monthly_interest_rate' => $values['monthly_interest_rate'],
                'issue_date' => $values['issue_date'],
                'due_date' => $values['due_date'],
            ]);

            $insert[] = $billing;
        }

        if ($store && $insert !== []) {
            // O insert em lote não passa pelo Eloquent, então não dispara o
            // observer: a versão dos dados sobe aqui, na mesma transação do lote.
            DB::transaction(function () use ($insert): void {
                DB::table('billings')->insert($insert);
                $this->version->bump();
            });

            $report->importedCount += count($insert);
        }
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function normalize(array $values): array
    {
        return [
            'document' => preg_replace('/\D/', '', $values['document'] ?? '') ?? '',
            'description' => trim($values['description'] ?? ''),
            'original_amount' => $this->normalizeNumber($values['original_amount'] ?? ''),
            // Taxa ausente vira zero: cobrança sem juros é cobrança legítima, e
            // exigir a coluna recusaria arquivo de quem não cobra juros.
            'monthly_interest_rate' => $this->normalizeNumber($values['monthly_interest_rate'] ?? '0'),
            'issue_date' => $this->data($values['issue_date'] ?? ''),
            'due_date' => $this->data($values['due_date'] ?? ''),
        ];
    }

    /**
     * Aceita 1.234,56 e 1234.56.
     *
     * O Excel em português escreve a primeira forma, e recusá-la faria o
     * arquivo exportado da própria planilha do usuário não servir. A regra é
     * simples: se tem vírgula, ela é o separador decimal e o ponto é de
     * milhar.
     */
    private function normalizeNumber(string $value): string
    {
        $clean = trim($value);

        if ($clean === '') {
            return '0';
        }

        if (str_contains($clean, ',')) {
            $clean = str_replace(['.', ','], ['', '.'], $clean);
        }

        return $clean;
    }

    /**
     * Aceita 2026-08-09 e 09/08/2026.
     *
     * `DateTimeImmutable` e não `CarbonImmutable`: o Carbon LANÇA exceção
     * quando o valor não casa com o formato, em vez de devolver false como o
     * nativo. Aqui a tentativa que falha é o caso normal — são quatro formatos
     * testados em sequência — e usar exceção para fluxo esperado custa caro e
     * lê pior.
     *
     * A volta com `format` e a comparação existem porque os dois aceitam
     * 32/13/2026 e rolam para o mês seguinte. Sem ela, data inválida viraria
     * cobrança com vencimento errado em vez de erro na linha.
     */
    private function data(string $value): string
    {
        $clean = trim($value);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $format) {
            $data = DateTimeImmutable::createFromFormat($format, $clean);

            if ($data !== false && $data->format($format) === $clean) {
                return $data->format('Y-m-d');
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<int, string>
     */
    private function validate(array $values): array
    {
        $validator = Validator::make($values, [
            'document' => ['required', 'string', 'regex:/^(\d{11}|\d{14})$/'],
            'description' => ['required', 'string', 'max:255'],
            'original_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'monthly_interest_rate' => ['required', 'numeric', 'min:0', 'max:9.9999'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
        ], [
            'document.regex' => 'O documento do cliente deve ser um CPF (11 dígitos) ou CNPJ (14 dígitos).',
            'issue_date.date_format' => 'Data de emissão inválida. Use AAAA-MM-DD ou DD/MM/AAAA.',
            'due_date.date_format' => 'Data de vencimento inválida. Use AAAA-MM-DD ou DD/MM/AAAA.',
            'due_date.after_or_equal' => 'O vencimento não pode ser anterior à emissão.',
        ]);

        return $validator->fails() ? $validator->errors()->all() : [];
    }
}
