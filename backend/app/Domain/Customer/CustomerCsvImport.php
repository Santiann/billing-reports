<?php

namespace App\Domain\Customer;

use App\Domain\Import\CsvReader;
use App\Domain\Import\ImportReport;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Imports customers from a CSV.
 *
 * Three decisions govern this class:
 *
 * **An invalid row does not abort the file.** The good ones go in, the bad ones come back
 * named with the line and the reason. Aborting everything because of a wrong email on line
 * 47 would force the user to fix and resend the whole file.
 *
 * **The validation is the same as the screen's.** The rules come from here rather than from
 * the FormRequest because the import has no request per row, but they are the same rules —
 * a document of 11 or 14 digits, a valid email, a unique document. Two lists of rules would
 * drift apart at the first adjustment.
 *
 * **Batch inserts, with uniqueness checked beforehand.** One query per row would be slow,
 * and a batch insert without checking would hit the database's unique index and bring the
 * whole block down because of one row. The batch checks the block's documents against the
 * database in a single query, and against itself in an in-memory set — which holds
 * documents, not rows.
 */
final class CustomerCsvImport
{
    /** Rows per INSERT, and per uniqueness query. */
    private const BATCH = 500;

    private const COLUMNS = [
        'name' => ['nome', 'name', 'razaosocial', 'cliente'],
        'document' => ['documento', 'document', 'cpf', 'cnpj', 'cpfcnpj'],
        'email' => ['email', 'mail'],
        'status' => ['status', 'situacao'],
    ];

    /** Status accepted in both languages, because the file can come from anywhere. */
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

        /** @var array<string, int> document => the line it appeared on */
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
     * Closes a batch: drops the ones that already exist in the database and writes the rest.
     *
     * The check happens here, and not row by row, because one query per row would turn a
     * ten-thousand-customer file into ten thousand queries.
     *
     * @param  array<int, array<string, mixed>>  $batch  line => values
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
            // Digits only, as on the screen: the search cannot depend on whatever
            // formatting came in the spreadsheet.
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
