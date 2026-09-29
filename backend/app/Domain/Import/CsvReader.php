<?php

namespace App\Domain\Import;

use Generator;
use RuntimeException;

/**
 * Lê um CSV linha a linha, com o cabeçalho traduzido para nomes de campo.
 *
 * Linha a linha, e não `file()` nem `str_getcsv` no conteúdo inteiro: um
 * arquivo de cem mil clientes não pode existir de uma vez na memória do
 * processo. O gerador devolve uma linha por vez e esquece a anterior.
 *
 * Duas conveniências que vêm da realidade de quem exporta planilha:
 *
 * - O separador é detectado. O Excel em português salva com `;`, e o resto do
 *   mundo com `,`. Exigir um dos dois transformaria "o arquivo não funciona"
 *   num problema de suporte.
 * - O cabeçalho aceita apelidos. `nome` e `name` são a mesma coluna; quem
 *   exportou do próprio sistema e quem montou a planilha à mão chegam com
 *   nomes diferentes para o mesmo dado.
 */
final class CsvReader
{
    /** O BOM que o Excel escreve no começo do arquivo, e que não é dado. */
    private const BOM = "\xEF\xBB\xBF";

    /**
     * @param  array<string, array<int, string>>  $columns  campo => apelidos aceitos
     */
    public function __construct(
        private readonly array $columns,
        private readonly array $required,
    ) {}

    /**
     * Percorre o arquivo devolvendo [numeroDaLinha, valoresPorCampo].
     *
     * O número é o da LINHA DO ARQUIVO, contando o cabeçalho — é assim que o
     * usuário vai encontrar o erro ao abrir a planilha.
     *
     * @return Generator<int, array{0: int, 1: array<string, string>}>
     *
     * @throws RuntimeException quando o cabeçalho não tem as colunas exigidas
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

                // fgetcsv devolve [null] para linha em branco, inclusive a do
                // fim do arquivo. Pular é o que evita um "erro na linha 6" que
                // o usuário não consegue ver na planilha.
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
     * Detecta o separador pela primeira linha.
     *
     * Conta ocorrências fora de aspas seria mais correto, mas cabeçalho com
     * aspas é raro o bastante para não valer o custo: o que decide é qual dos
     * dois aparece mais.
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
     * Liga cada posição do cabeçalho a um campo.
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
                // O nome que o usuário precisa DIGITAR, não o nome interno do
                // campo: dizer que falta "document" manda procurar no arquivo
                // uma palavra que o cabeçalho dele nunca vai ter.
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

    /** Sem BOM, sem acento, sem caixa: "E-mail" e "email" são a mesma coluna. */
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
