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
    private const LOTE = 500;

    public function __construct(
        private readonly BillingDataVersion $versao = new BillingDataVersion(),
    ) {}

    private const COLUNAS = [
        'document' => ['documento', 'document', 'cliente', 'cpf', 'cnpj', 'cpfcnpj'],
        'description' => ['descricao', 'description', 'historico', 'referencia'],
        'original_amount' => ['valor', 'amount', 'valororiginal', 'originalamount'],
        'monthly_interest_rate' => ['taxa', 'juros', 'rate', 'taxamensal', 'monthlyinterestrate'],
        'issue_date' => ['emissao', 'dataemissao', 'issuedate'],
        'due_date' => ['vencimento', 'datavencimento', 'duedate'],
    ];

    public function preview(string $caminho): ImportReport
    {
        return $this->processar($caminho, gravar: false);
    }

    public function import(string $caminho): ImportReport
    {
        return $this->processar($caminho, gravar: true);
    }

    private function processar(string $caminho, bool $gravar): ImportReport
    {
        $leitor = new CsvReader(self::COLUNAS, [
            'document', 'description', 'original_amount', 'issue_date', 'due_date',
        ]);

        $relatorio = new ImportReport();
        $lote = [];

        foreach ($leitor->rows($caminho) as [$linha, $valores]) {
            $relatorio->totalRows++;

            $normalizado = $this->normalizar($valores);
            $erros = $this->validar($normalizado);

            if ($erros !== []) {
                $relatorio->addError($linha, $erros, $valores);

                continue;
            }

            // A linha só é contada como válida depois que o cliente é
            // encontrado, e isso acontece no fechamento do lote.
            $lote[$linha] = $normalizado;

            if (count($lote) >= self::LOTE) {
                $this->descarregar($lote, $relatorio, $gravar);
                $lote = [];
            }
        }

        if ($lote !== []) {
            $this->descarregar($lote, $relatorio, $gravar);
        }

        return $relatorio;
    }

    /**
     * Fecha um lote: resolve os clientes e grava o que sobrou.
     *
     * @param  array<int, array<string, string>>  $lote  linha => valores
     */
    private function descarregar(array $lote, ImportReport $relatorio, bool $gravar): void
    {
        $clientes = Customer::query()
            ->whereIn('document', array_unique(array_column($lote, 'document')))
            ->pluck('id', 'document');

        $agora = now();
        $inserir = [];

        foreach ($lote as $linha => $valores) {
            $clienteId = $clientes[$valores['document']] ?? null;

            if ($clienteId === null) {
                $relatorio->addError(
                    $linha,
                    ['Nenhum cliente cadastrado com este documento.'],
                    // As mesmas chaves que um erro de validação devolve: a tela
                    // exibe o registro por uma delas, e trocar o nome do campo
                    // aqui faria metade dos erros aparecer sem identificação.
                    ['document' => $valores['document'], 'description' => $valores['description']],
                );

                continue;
            }

            $relatorio->validCount++;

            $cobranca = [
                'customer_id' => $clienteId,
                'description' => $valores['description'],
                'original_amount' => $valores['original_amount'],
                'monthly_interest_rate' => $valores['monthly_interest_rate'],
                'issue_date' => $valores['issue_date'],
                'due_date' => $valores['due_date'],
                // Nasce pendente, sem exceção. O arquivo não decide isto.
                'status' => BillingStatus::Pending->value,
                'payment_date' => null,
                'paid_amount' => null,
                'paid_interest_amount' => null,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];

            $relatorio->addSample([
                'document' => $valores['document'],
                'description' => $valores['description'],
                'original_amount' => $valores['original_amount'],
                'monthly_interest_rate' => $valores['monthly_interest_rate'],
                'issue_date' => $valores['issue_date'],
                'due_date' => $valores['due_date'],
            ]);

            $inserir[] = $cobranca;
        }

        if ($gravar && $inserir !== []) {
            // O insert em lote não passa pelo Eloquent, então não dispara o
            // observer: a versão dos dados sobe aqui, na mesma transação do lote.
            DB::transaction(function () use ($inserir): void {
                DB::table('billings')->insert($inserir);
                $this->versao->bump();
            });

            $relatorio->importedCount += count($inserir);
        }
    }

    /**
     * @param  array<string, string>  $valores
     * @return array<string, string>
     */
    private function normalizar(array $valores): array
    {
        return [
            'document' => preg_replace('/\D/', '', $valores['document'] ?? '') ?? '',
            'description' => trim($valores['description'] ?? ''),
            'original_amount' => $this->numero($valores['original_amount'] ?? ''),
            // Taxa ausente vira zero: cobrança sem juros é cobrança legítima, e
            // exigir a coluna recusaria arquivo de quem não cobra juros.
            'monthly_interest_rate' => $this->numero($valores['monthly_interest_rate'] ?? '0'),
            'issue_date' => $this->data($valores['issue_date'] ?? ''),
            'due_date' => $this->data($valores['due_date'] ?? ''),
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
    private function numero(string $valor): string
    {
        $limpo = trim($valor);

        if ($limpo === '') {
            return '0';
        }

        if (str_contains($limpo, ',')) {
            $limpo = str_replace(['.', ','], ['', '.'], $limpo);
        }

        return $limpo;
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
    private function data(string $valor): string
    {
        $limpo = trim($valor);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $formato) {
            $data = DateTimeImmutable::createFromFormat($formato, $limpo);

            if ($data !== false && $data->format($formato) === $limpo) {
                return $data->format('Y-m-d');
            }
        }

        return $limpo;
    }

    /**
     * @param  array<string, string>  $valores
     * @return array<int, string>
     */
    private function validar(array $valores): array
    {
        $validador = Validator::make($valores, [
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

        return $validador->fails() ? $validador->errors()->all() : [];
    }
}
