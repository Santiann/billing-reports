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
    private const LOTE = 500;

    private const COLUNAS = [
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
        $leitor = new CsvReader(self::COLUNAS, ['name', 'document', 'email']);
        $relatorio = new ImportReport();

        /** @var array<string, int> documento => linha em que apareceu */
        $vistos = [];
        $lote = [];
        $agora = now();

        foreach ($leitor->rows($caminho) as [$linha, $valores]) {
            $relatorio->totalRows++;

            $normalizado = $this->normalizar($valores);
            $erros = $this->validar($normalizado);

            if (isset($vistos[$normalizado['document']])) {
                $erros[] = sprintf(
                    'Documento repetido no arquivo: já apareceu na linha %d.',
                    $vistos[$normalizado['document']],
                );
            }

            if ($erros !== []) {
                $relatorio->addError($linha, $erros, $valores);

                continue;
            }

            $vistos[$normalizado['document']] = $linha;
            $relatorio->validCount++;
            $relatorio->addSample($normalizado);

            $lote[$linha] = $normalizado + ['created_at' => $agora, 'updated_at' => $agora];

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
     * Fecha um lote: tira os que já existem no banco e grava o resto.
     *
     * A checagem acontece aqui, e não linha a linha, porque uma consulta por
     * linha transformaria um arquivo de dez mil clientes em dez mil consultas.
     *
     * @param  array<int, array<string, mixed>>  $lote  linha => valores
     */
    private function descarregar(array $lote, ImportReport $relatorio, bool $gravar): void
    {
        $documentos = array_column($lote, 'document');

        $existentes = Customer::query()
            ->whereIn('document', $documentos)
            ->pluck('document')
            ->flip();

        $inserir = [];

        foreach ($lote as $linha => $valores) {
            if ($existentes->has($valores['document'])) {
                $relatorio->validCount--;
                $relatorio->addError(
                    $linha,
                    ['Já existe um cliente com este documento.'],
                    ['name' => $valores['name'], 'document' => $valores['document']],
                );

                continue;
            }

            $inserir[] = $valores;
        }

        if ($gravar && $inserir !== []) {
            DB::table('customers')->insert($inserir);
            $relatorio->importedCount += count($inserir);
        }
    }

    /**
     * @param  array<string, string>  $valores
     * @return array<string, string>
     */
    private function normalizar(array $valores): array
    {
        $status = mb_strtolower(trim($valores['status'] ?? ''));

        return [
            'name' => trim($valores['name'] ?? ''),
            // Só dígitos, como na tela: a busca não pode depender da máscara
            // que veio na planilha.
            'document' => preg_replace('/\D/', '', $valores['document'] ?? '') ?? '',
            'email' => mb_strtolower(trim($valores['email'] ?? '')),
            'status' => self::STATUS[$status] ?? $status,
        ];
    }

    /**
     * @param  array<string, string>  $valores
     * @return array<int, string>
     */
    private function validar(array $valores): array
    {
        $validador = Validator::make($valores, [
            'name' => ['required', 'string', 'max:255'],
            'document' => ['required', 'string', 'regex:/^(\d{11}|\d{14})$/'],
            'email' => ['required', 'email', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ], [
            'document.regex' => 'O documento deve ser um CPF (11 dígitos) ou CNPJ (14 dígitos).',
            'status.in' => 'O status deve ser ativo ou inativo.',
        ]);

        return $validador->fails() ? $validador->errors()->all() : [];
    }
}
