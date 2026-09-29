<?php

namespace App\Domain\Import;

/**
 * What happened to the file.
 *
 * It exists because a partial import is acceptable, and a partial import is only acceptable if
 * the user knows exactly what went in and what did not. A success counter on its own hides the
 * half that matters.
 */
final class ImportReport
{
    public int $totalRows = 0;

    public int $validCount = 0;

    public int $importedCount = 0;

    /** @var array<int, array{line: int, messages: array<int, string>, values: array<string, string>}> */
    public array $errors = [];

    /** @var array<int, array<string, mixed>> */
    public array $sample = [];

    /**
     * The cap on stored errors.
     *
     * A file with the wrong header produces one error per row, and returning a hundred thousand
     * of them helps nobody — not the user, who will not read them, nor the process, which would
     * have to hold them all in memory. The count stays exact; what stops growing is the list.
     */
    public const MAX_ERRORS = 50;

    /** Rows shown in the preview. */
    public const MAX_SAMPLE = 10;

    /**
     * @param  array<int, string>  $messages
     * @param  array<string, string>  $values
     */
    public function addError(int $line, array $messages, array $values): void
    {
        if (count($this->errors) < self::MAX_ERRORS) {
            $this->errors[] = [
                'line' => $line,
                'messages' => array_values($messages),
                'values' => $values,
            ];
        }
    }

    /** @param array<string, mixed> $values */
    public function addSample(array $values): void
    {
        if (count($this->sample) < self::MAX_SAMPLE) {
            $this->sample[] = $values;
        }
    }

    public function errorCount(): int
    {
        return $this->totalRows - $this->validCount;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'valid_count' => $this->validCount,
            'imported_count' => $this->importedCount,
            'error_count' => $this->errorCount(),
            'errors_truncated' => $this->errorCount() > count($this->errors),
            'errors' => $this->errors,
            'sample' => $this->sample,
        ];
    }
}
