<?php

namespace App\Domain\Billing;

use App\Domain\Import\CsvReader;
use App\Domain\Import\ImportReport;
use App\Models\Customer;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Imports billings from a CSV.
 *
 * The same structure as the customer import — generator-based reading, per-row
 * validation, batch insert, a report carrying the line and the reason — with two rules
 * of its own:
 *
 * **The customer is resolved by DOCUMENT.** The file comes from outside and does not
 * know the internal id; the document is the business identity both ends have. Resolution
 * happens per batch, in one query that brings back the customers for all 500 documents
 * at once — resolving row by row would turn a ten-thousand-billing file into ten
 * thousand queries.
 *
 * **A billing is born pending.** Status and payment amounts are not read from the file
 * even if they are there. Accepting `status = paid` would create a paid billing without
 * the frozen amounts, which is exactly what the create form refuses too — what makes
 * that transition is recording a payment.
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

            // A row only counts as valid once the customer has been found, and that
            // happens when the batch is closed.
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
     * Closes a batch: resolves the customers and writes whatever is left.
     *
     * @param  array<int, array<string, string>>  $batch  line => values
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
                    // The same keys a validation error returns: the screen shows the
                    // record by one of them, and changing the field name here would
                    // leave half the errors displayed without identification.
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
                // Born pending, no exceptions. The file does not decide this.
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
            // The batch insert does not go through Eloquent, so it does not fire the
            // observer: the data version is bumped here, in the batch's own transaction.
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
            // A missing rate becomes zero: a billing with no interest is a legitimate
            // billing, and requiring the column would refuse files from whoever does not
            // charge interest.
            'monthly_interest_rate' => $this->normalizeNumber($values['monthly_interest_rate'] ?? '0'),
            'issue_date' => $this->data($values['issue_date'] ?? ''),
            'due_date' => $this->data($values['due_date'] ?? ''),
        ];
    }

    /**
     * Accepts 1.234,56 and 1234.56.
     *
     * Excel in Portuguese writes the first form, and refusing it would make the file
     * exported from the user's own spreadsheet useless. The rule is simple: if there is a
     * comma, it is the decimal separator and the dot is the thousands one.
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
     * Accepts 2026-08-09 and 09/08/2026.
     *
     * `DateTimeImmutable` and not `CarbonImmutable`: Carbon THROWS when the value does not
     * match the format, instead of returning false like the native one. Here the failing
     * attempt is the normal case — four formats are tried in sequence — and using an
     * exception for expected flow is expensive and reads worse.
     *
     * The round trip through `format` and the comparison exist because both accept
     * 32/13/2026 and roll over into the following month. Without it, an invalid date would
     * become a billing with the wrong due date instead of an error on the row.
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
