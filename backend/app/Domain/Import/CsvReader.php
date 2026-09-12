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
     * @param  array<string, array<int, string>>  $colunas  campo => apelidos aceitos
     */
    public function __construct(
        private readonly array $colunas,
        private readonly array $obrigatorias,
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
    public function rows(string $caminho): Generator
    {
        $arquivo = fopen($caminho, 'rb');

        if ($arquivo === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo enviado.');
        }

        try {
            $separador = $this->separador($arquivo);
            $cabecalho = fgetcsv($arquivo, 0, $separador);

            if ($cabecalho === false) {
                throw new RuntimeException('O arquivo está vazio.');
            }

            $mapa = $this->mapear($cabecalho);
            $linha = 1;

            while (($valores = fgetcsv($arquivo, 0, $separador)) !== false) {
                $linha++;

                // fgetcsv devolve [null] para linha em branco, inclusive a do
                // fim do arquivo. Pular é o que evita um "erro na linha 6" que
                // o usuário não consegue ver na planilha.
                if ($valores === [null] || $this->vazia($valores)) {
                    continue;
                }

                yield [$linha, $this->associar($mapa, $valores)];
            }
        } finally {
            fclose($arquivo);
        }
    }

    /**
     * Detecta o separador pela primeira linha.
     *
     * Conta ocorrências fora de aspas seria mais correto, mas cabeçalho com
     * aspas é raro o bastante para não valer o custo: o que decide é qual dos
     * dois aparece mais.
     *
     * @param  resource  $arquivo
     */
    private function separador($arquivo): string
    {
        $primeira = fgets($arquivo);
        rewind($arquivo);

        if ($primeira === false) {
            return ';';
        }

        return substr_count($primeira, ';') >= substr_count($primeira, ',') ? ';' : ',';
    }

    /**
     * Liga cada posição do cabeçalho a um campo.
     *
     * @param  array<int, string|null>  $cabecalho
     * @return array<int, string>
     */
    private function mapear(array $cabecalho): array
    {
        $mapa = [];

        foreach ($cabecalho as $posicao => $titulo) {
            $normalizado = $this->normalizar((string) ($titulo ?? ''));

            foreach ($this->colunas as $campo => $apelidos) {
                if (in_array($normalizado, $apelidos, true)) {
                    $mapa[$posicao] = $campo;
                    break;
                }
            }
        }

        $faltando = array_diff($this->obrigatorias, array_values($mapa));

        if ($faltando !== []) {
            throw new RuntimeException(sprintf(
                'O arquivo precisa das colunas: %s. Cabeçalho recebido: %s.',
                // O nome que o usuário precisa DIGITAR, não o nome interno do
                // campo: dizer que falta "document" manda procurar no arquivo
                // uma palavra que o cabeçalho dele nunca vai ter.
                implode(', ', array_map(fn (string $campo) => $this->colunas[$campo][0], $faltando)),
                implode(', ', array_map(fn ($t) => (string) $t, $cabecalho)),
            ));
        }

        return $mapa;
    }

    /**
     * @param  array<int, string>  $mapa
     * @param  array<int, string|null>  $valores
     * @return array<string, string>
     */
    private function associar(array $mapa, array $valores): array
    {
        $linha = [];

        foreach ($mapa as $posicao => $campo) {
            $linha[$campo] = trim((string) ($valores[$posicao] ?? ''));
        }

        return $linha;
    }

    /** @param array<int, string|null> $valores */
    private function vazia(array $valores): bool
    {
        foreach ($valores as $valor) {
            if (trim((string) $valor) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Sem BOM, sem acento, sem caixa: "E-mail" e "email" são a mesma coluna. */
    private function normalizar(string $titulo): string
    {
        $limpo = str_replace(self::BOM, '', $titulo);
        $semAcento = iconv('UTF-8', 'ASCII//TRANSLIT', $limpo);

        return preg_replace(
            '/[^a-z0-9]/',
            '',
            mb_strtolower($semAcento === false ? $limpo : $semAcento),
        ) ?? '';
    }
}
