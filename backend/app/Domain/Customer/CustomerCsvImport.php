<?php

namespace App\Domain\Customer;

use App\Domain\Import\CsvReader;
use App\Domain\Import\ImportReport;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Importa clientes de um CSV.
 *
 * Três decisões governam esta classe:
 *
 * **Linha inválida não aborta o arquivo.** As boas entram, as ruins voltam
 * nomeadas com a linha e o motivo. Abortar tudo por causa de um e-mail errado
 * na linha 47 obrigaria o usuário a corrigir e reenviar o arquivo inteiro.
 *
 * **A validação é a mesma da tela.** As regras vêm daqui em vez de do
 * FormRequest porque o import não tem requisição por linha, mas são as mesmas
 * regras — documento de 11 ou 14 dígitos, e-mail válido, documento único.
 * Duas listas de regras divergiriam no primeiro ajuste.
 *
 * **Insert em lote, com a unicidade checada antes.** Uma consulta por linha
 * seria lenta, e um insert em lote sem checar estouraria a unique do banco e
 * derrubaria o bloco inteiro por causa de uma linha. O lote checa os documentos
 * do bloco contra o banco numa consulta só, e contra si mesmo num conjunto em
 * memória — que guarda documentos, não linhas.
 */
final class CustomerCsvImport
{
    /** Linhas por INSERT, e por consulta de unicidade. */
    private const BATCH = 500;

    private const COLUMNS = [
        'name' => ['nome', 'name', 'razaosocial', 'cliente'],
        'document' => ['documento', 'document', 'cpf', 'cnpj', 'cpfcnpj'],
        'email' => ['email', 'mail'],
        'status' => ['status', 'situacao'],
    ];

    /** Status aceito nos dois idiomas, porque o arquivo pode vir de qualquer lado. */
    private const STATUS = [
        'ativo' => 'active',
        'active' => 'active',
        'inativo' => 'inactive',
        'inactive' => 'inactive',
        '' => 'active',
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
        $reader = new CsvReader(self::COLUMNS, ['name', 'document', 'email']);
        $report = new ImportReport();

        /** @var array<string, int> documento => linha em que apareceu */
        $seen = [];
        $batch = [];
        $now = now();

        foreach ($reader->rows($path) as [$row, $values]) {
            $report->totalRows++;

            $normalized = $this->normalize($values);
            $errors = $this->validate($normalized);

            if (isset($seen[$normalized['document']])) {
                $errors[] = sprintf(
                    'Documento repetido no arquivo: já apareceu na linha %d.',
                    $seen[$normalized['document']],
                );
            }

            if ($errors !== []) {
                $report->addError($row, $errors, $values);

                continue;
            }

            $seen[$normalized['document']] = $row;
            $report->validCount++;
            $report->addSample($normalized);

            $batch[$row] = $normalized + ['created_at' => $now, 'updated_at' => $now];

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
     * Fecha um lote: tira os que já existem no banco e grava o resto.
     *
     * A checagem acontece aqui, e não linha a linha, porque uma consulta por
     * linha transformaria um arquivo de dez mil clientes em dez mil consultas.
     *
     * @param  array<int, array<string, mixed>>  $batch  linha => valores
     */
    private function flush(array $batch, ImportReport $report, bool $store): void
    {
        $documents = array_column($batch, 'document');

        $existentes = Customer::query()
            ->whereIn('document', $documents)
            ->pluck('document')
            ->flip();

        $insert = [];

        foreach ($batch as $row => $values) {
            if ($existentes->has($values['document'])) {
                $report->validCount--;
                $report->addError(
                    $row,
                    ['Já existe um cliente com este documento.'],
                    ['name' => $values['name'], 'document' => $values['document']],
                );

                continue;
            }

            $insert[] = $values;
        }

        if ($store && $insert !== []) {
            DB::table('customers')->insert($insert);
            $report->importedCount += count($insert);
        }
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function normalize(array $values): array
    {
        $status = mb_strtolower(trim($values['status'] ?? ''));

        return [
            'name' => trim($values['name'] ?? ''),
            // Só dígitos, como na tela: a busca não pode depender da máscara
            // que veio na planilha.
            'document' => preg_replace('/\D/', '', $values['document'] ?? '') ?? '',
            'email' => mb_strtolower(trim($values['email'] ?? '')),
            'status' => self::STATUS[$status] ?? $status,
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @return array<int, string>
     */
    private function validate(array $values): array
    {
        $validator = Validator::make($values, [
            'name' => ['required', 'string', 'max:255'],
            'document' => ['required', 'string', 'regex:/^(\d{11}|\d{14})$/'],
            'email' => ['required', 'email', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ], [
            'document.regex' => 'O documento deve ser um CPF (11 dígitos) ou CNPJ (14 dígitos).',
            'status.in' => 'O status deve ser ativo ou inativo.',
        ]);

        return $validator->fails() ? $validator->errors()->all() : [];
    }
}
