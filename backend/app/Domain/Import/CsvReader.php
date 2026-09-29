<?php

namespace App\Domain\Import;

use Generator;
use RuntimeException;

/**
 * Reads a CSV row by row, with the header translated into field names.
 *
 * Row by row, and not `file()` nor `str_getcsv` over the whole content: a file with a
 * hundred thousand customers cannot exist all at once in the process's memory. The
 * generator returns one row at a time and forgets the previous one.
 *
 * Two conveniences that come from the reality of people exporting spreadsheets:
 *
 * - The separator is detected. Excel in Portuguese saves with `;`, and the rest of the
 *   world with `,`. Demanding one of the two would turn "the file does not work" into a
 *   support problem.
 * - The header accepts aliases. `nome` and `name` are the same column; someone who
 *   exported from this system and someone who built the spreadsheet by hand arrive with
 *   different names for the same data.
 */
final class CsvReader
{
    /** The BOM Excel writes at the start of the file, which is not data. */
    private const BOM = "\xEF\xBB\xBF";

    /**
     * @param  array<string, array<int, string>>  $columns  field => accepted aliases
     */
    public function __construct(
        private readonly array $columns,
        private readonly array $required,
    ) {}

    /**
     * Walks the file returning [lineNumber, valuesByField].
     *
     * The number is the FILE'S LINE, counting the header — that is how the user will
     * find the error when they open the spreadsheet.
     *
     * @return Generator<int, array{0: int, 1: array<string, string>}>
     *
     * @throws RuntimeException when the header is missing the required columns
     */
    public function rows(string $path): Generator
    {
        $file = fopen($path, 'rb');

        if ($file === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo enviado.');
        }

        try {
            $separator = $this->separator($file);
            $header = fgetcsv($file, 0, $separator);

            if ($header === false) {
                throw new RuntimeException('O arquivo está vazio.');
            }

            $map = $this->map($header);
            $row = 1;

            while (($values = fgetcsv($file, 0, $separator)) !== false) {
                $row++;

                // fgetcsv returns [null] for a blank line, including the one at the end
                // of the file. Skipping is what avoids an "error on line 6" the user
                // cannot see in the spreadsheet.
                if ($values === [null] || $this->isEmptyRow($values)) {
                    continue;
                }

                yield [$row, $this->combine($map, $values)];
            }
        } finally {
            fclose($file);
        }
    }

    /**
     * Detects the separator from the first line.
     *
     * Counting occurrences outside quotes would be more correct, but a quoted header is
     * rare enough not to be worth the cost: what decides is which of the two appears
     * more often.
     *
     * @param  resource  $file
     */
    private function separator($file): string
    {
        $firstOne = fgets($file);
        rewind($file);

        if ($firstOne === false) {
            return ';';
        }

        return substr_count($firstOne, ';') >= substr_count($firstOne, ',') ? ';' : ',';
    }

    /**
     * Ties each header position to a field.
     *
     * @param  array<int, string|null>  $header
     * @return array<int, string>
     */
    private function map(array $header): array
    {
        $map = [];

        foreach ($header as $position => $title) {
            $normalized = $this->normalize((string) ($title ?? ''));

            foreach ($this->columns as $field => $aliases) {
                if (in_array($normalized, $aliases, true)) {
                    $map[$position] = $field;
                    break;
                }
            }
        }

        $missing = array_diff($this->required, array_values($map));

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'O arquivo precisa das colunas: %s. Cabeçalho recebido: %s.',
                // The name the user has to TYPE, not the field's internal name: saying
                // "document" is missing sends them looking in the file for a word their
                // header will never contain.
                implode(', ', array_map(fn (string $field) => $this->columns[$field][0], $missing)),
                implode(', ', array_map(fn ($t) => (string) $t, $header)),
            ));
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $map
     * @param  array<int, string|null>  $values
     * @return array<string, string>
     */
    private function combine(array $map, array $values): array
    {
        $row = [];

        foreach ($map as $position => $field) {
            $row[$field] = trim((string) ($values[$position] ?? ''));
        }

        return $row;
    }

    /** @param array<int, string|null> $values */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** No BOM, no accents, no case: "E-mail" and "email" are the same column. */
    private function normalize(string $title): string
    {
        $clean = str_replace(self::BOM, '', $title);
        $withoutAccent = iconv('UTF-8', 'ASCII//TRANSLIT', $clean);

        return preg_replace(
            '/[^a-z0-9]/',
            '',
            mb_strtolower($withoutAccent === false ? $clean : $withoutAccent),
        ) ?? '';
    }
}
