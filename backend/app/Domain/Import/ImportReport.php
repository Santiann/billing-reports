<?php

namespace App\Domain\Import;

/**
 * O que aconteceu com o arquivo.
 *
 * Existe porque importação parcial é aceitável, e importação parcial só é
 * aceitável se o usuário souber exatamente o que entrou e o que não entrou.
 * Um contador de sucesso sozinho esconde a metade que importa.
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
     * Teto de erros guardados.
     *
     * Um arquivo com cabeçalho errado gera um erro por linha, e devolver cem
     * mil deles não ajuda ninguém — nem o usuário, que não vai ler, nem o
     * processo, que teria que segurar tudo em memória. A contagem continua
     * exata; o que para de crescer é a lista.
     */
    public const MAX_ERROS = 50;

    /** Linhas mostradas na prévia. */
    public const MAX_AMOSTRA = 10;

    /**
     * @param  array<int, string>  $messages
     * @param  array<string, string>  $values
     */
    public function addError(int $line, array $messages, array $values): void
    {
        if (count($this->errors) < self::MAX_ERROS) {
            $this->errors[] = [
                'line' => $line,
                'messages' => array_values($messages),
                'values' => $values,
            ];
        }
    }

    /** @param array<string, mixed> $valores */
    public function addSample(array $valores): void
    {
        if (count($this->sample) < self::MAX_AMOSTRA) {
            $this->sample[] = $valores;
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
