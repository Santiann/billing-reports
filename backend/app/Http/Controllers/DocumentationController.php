<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\Yaml\Yaml;

/**
 * A documentação da API, montada no servidor.
 *
 * Renderizador de spec do mercado — Redoc, Scalar, Elements — resolve isto com
 * uma linha de HTML e uma tag de script, e fica bonito. Mas todos montam a
 * página no browser, e `curl localhost:8000` devolveria `<div id="app">` e nada
 * mais. A documentação precisa existir na resposta, não depois do JavaScript:
 * é o que permite ler pelo terminal, indexar e abrir sem internet.
 *
 * O custo da escolha é este arquivo: resolver `$ref`, fundir os parâmetros do
 * path com os da operação e formatar exemplo. Em troca, a página responde a
 * `curl` e não depende de CDN nenhuma.
 */
class DocumentationController extends Controller
{
    public function page(): View
    {
        $spec = $this->spec();

        return view('documentation', [
            'spec' => $spec,
            'grupos' => $this->agruparPorTag($spec),
        ]);
    }

    /**
     * O YAML cru, para importar em Postman, Insomnia ou num gerador de cliente.
     *
     * A página é para ler; o arquivo é para usar.
     */
    public function raw(): Response
    {
        return response(
            (string) file_get_contents($this->caminho()),
            200,
            ['Content-Type' => 'application/yaml'],
        );
    }

    private function caminho(): string
    {
        return resource_path('openapi.yaml');
    }

    /**
     * Sem cache, de propósito.
     *
     * O parse leva poucos milissegundos e esta não é o caminho quente de nada.
     * Em compensação, editar a spec e recarregar mostra o resultado na hora —
     * que é o que se quer de um arquivo mantido à mão.
     *
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        return Yaml::parseFile($this->caminho());
    }

    /**
     * Reorganiza os endpoints por tag, na ordem em que as tags aparecem.
     *
     * A spec é indexada por caminho porque o formato exige; quem lê procura por
     * assunto. Os parâmetros declarados no nível do path são fundidos aos da
     * operação, porque para quem lê a distinção não existe: são todos
     * parâmetros daquela chamada.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function agruparPorTag(array $spec): array
    {
        $grupos = [];

        foreach ($spec['tags'] ?? [] as $tag) {
            $grupos[$tag['name']] = [];
        }

        foreach ($spec['paths'] as $caminho => $entrada) {
            $doPath = $entrada['parameters'] ?? [];

            foreach ($entrada as $metodo => $operacao) {
                if ($metodo === 'parameters') {
                    continue;
                }

                $tag = $operacao['tags'][0] ?? 'Outros';

                $grupos[$tag][] = [
                    'metodo' => strtoupper($metodo),
                    'caminho' => $caminho,
                    'ancora' => $operacao['operationId'] ?? md5($metodo.$caminho),
                    'resumo' => $operacao['summary'] ?? '',
                    'descricao' => $operacao['description'] ?? null,
                    // `security: []` na operação declara rota pública.
                    'publica' => ($operacao['security'] ?? null) === [],
                    'parametros' => $this->parametros($spec, array_merge($doPath, $operacao['parameters'] ?? [])),
                    'corpo' => $this->corpo($spec, $operacao['requestBody'] ?? null),
                    'respostas' => $this->respostas($spec, $operacao['responses'] ?? []),
                ];
            }
        }

        return array_filter($grupos);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<int, array<string, mixed>>  $parametros
     * @return array<int, array<string, mixed>>
     */
    private function parametros(array $spec, array $parametros): array
    {
        return array_map(function (array $parametro) use ($spec) {
            $parametro = $this->resolver($spec, $parametro);
            $schema = $this->resolver($spec, $parametro['schema'] ?? []);

            return [
                'nome' => $parametro['name'] ?? '',
                'local' => $parametro['in'] ?? '',
                'obrigatorio' => (bool) ($parametro['required'] ?? false),
                'tipo' => $this->tipo($schema),
                'padrao' => $parametro['schema']['default'] ?? $schema['default'] ?? null,
                'descricao' => $parametro['description'] ?? null,
            ];
        }, $parametros);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>|null  $corpo
     * @return array<string, mixed>|null
     */
    private function corpo(array $spec, ?array $corpo): ?array
    {
        if ($corpo === null) {
            return null;
        }

        $corpo = $this->resolver($spec, $corpo);

        return [
            'obrigatorio' => (bool) ($corpo['required'] ?? false),
            'exemplos' => $this->exemplos($corpo['content'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<int|string, mixed>  $respostas
     * @return array<int, array<string, mixed>>
     */
    private function respostas(array $spec, array $respostas): array
    {
        $resolvidas = [];

        foreach ($respostas as $status => $resposta) {
            $resposta = $this->resolver($spec, $resposta);

            $resolvidas[] = [
                'status' => (string) $status,
                'descricao' => $resposta['description'] ?? '',
                'exemplos' => $this->exemplos($resposta['content'] ?? []),
            ];
        }

        return $resolvidas;
    }

    /**
     * Exemplo já formatado para leitura, por tipo de conteúdo.
     *
     * O exemplo do CSV é string e sai como está; os de JSON são estrutura e
     * saem indentados. `JSON_UNESCAPED_UNICODE` porque a API responde em
     * português e `é` no lugar de `é` não é exemplo, é charada.
     *
     * @param  array<string, mixed>  $content
     * @return array<int, array{tipo: string, exemplo: string}>
     */
    private function exemplos(array $content): array
    {
        $exemplos = [];

        foreach ($content as $tipo => $conteudo) {
            if (! isset($conteudo['example'])) {
                continue;
            }

            $exemplo = $conteudo['example'];

            $exemplos[] = [
                'tipo' => $tipo,
                'exemplo' => is_string($exemplo)
                    ? $exemplo
                    : (string) json_encode(
                        $exemplo,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ),
            ];
        }

        return $exemplos;
    }

    /**
     * Descreve o schema numa linha: tipo, formato e valores aceitos.
     *
     * @param  array<string, mixed>  $schema
     */
    private function tipo(array $schema): string
    {
        $tipo = $schema['type'] ?? 'string';
        $tipo = is_array($tipo) ? implode(' | ', $tipo) : $tipo;

        if (isset($schema['format'])) {
            $tipo .= " ({$schema['format']})";
        }

        if (isset($schema['enum'])) {
            $tipo .= ' — '.implode(', ', $schema['enum']);
        }

        return $tipo;
    }

    /**
     * Segue um `$ref` até o objeto apontado.
     *
     * A spec usa referência para não repetir o 401 em quinze lugares. Quem lê a
     * página precisa ver o conteúdo, não o ponteiro.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function resolver(array $spec, array $item): array
    {
        if (! isset($item['$ref'])) {
            return $item;
        }

        $alvo = $spec;

        foreach (explode('/', ltrim($item['$ref'], '#/')) as $segmento) {
            $alvo = $alvo[$segmento] ?? [];
        }

        return is_array($alvo) ? $alvo : [];
    }
}
