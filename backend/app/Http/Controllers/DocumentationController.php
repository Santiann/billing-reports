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
            'grupos' => $this->groupByTag($spec),
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
            (string) file_get_contents($this->path()),
            200,
            ['Content-Type' => 'application/yaml'],
        );
    }

    private function path(): string
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
        return Yaml::parseFile($this->path());
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
    private function groupByTag(array $spec): array
    {
        $groups = [];

        foreach ($spec['tags'] ?? [] as $tag) {
            $groups[$tag['name']] = [];
        }

        foreach ($spec['paths'] as $path => $input) {
            $doPath = $input['parameters'] ?? [];

            foreach ($input as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }

                $tag = $operation['tags'][0] ?? 'Outros';

                $groups[$tag][] = [
                    'metodo' => strtoupper($method),
                    'caminho' => $path,
                    'ancora' => $operation['operationId'] ?? md5($method.$path),
                    'resumo' => $operation['summary'] ?? '',
                    'descricao' => $operation['description'] ?? null,
                    // `security: []` na operação declara rota pública.
                    'publica' => ($operation['security'] ?? null) === [],
                    'parametros' => $this->parameters($spec, array_merge($doPath, $operation['parameters'] ?? [])),
                    'corpo' => $this->body($spec, $operation['requestBody'] ?? null),
                    'respostas' => $this->responses($spec, $operation['responses'] ?? []),
                ];
            }
        }

        return array_filter($groups);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<int, array<string, mixed>>  $parameters
     * @return array<int, array<string, mixed>>
     */
    private function parameters(array $spec, array $parameters): array
    {
        return array_map(function (array $parameter) use ($spec) {
            $parameter = $this->resolve($spec, $parameter);
            $schema = $this->resolve($spec, $parameter['schema'] ?? []);

            return [
                'nome' => $parameter['name'] ?? '',
                'local' => $parameter['in'] ?? '',
                'obrigatorio' => (bool) ($parameter['required'] ?? false),
                'tipo' => $this->type($schema),
                'padrao' => $parameter['schema']['default'] ?? $schema['default'] ?? null,
                'descricao' => $parameter['description'] ?? null,
            ];
        }, $parameters);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>|null
     */
    private function body(array $spec, ?array $body): ?array
    {
        if ($body === null) {
            return null;
        }

        $body = $this->resolve($spec, $body);

        return [
            'obrigatorio' => (bool) ($body['required'] ?? false),
            'exemplos' => $this->examples($body['content'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<int|string, mixed>  $responses
     * @return array<int, array<string, mixed>>
     */
    private function responses(array $spec, array $responses): array
    {
        $resolved = [];

        foreach ($responses as $status => $response) {
            $response = $this->resolve($spec, $response);

            $resolved[] = [
                'status' => (string) $status,
                'descricao' => $response['description'] ?? '',
                'exemplos' => $this->examples($response['content'] ?? []),
            ];
        }

        return $resolved;
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
    private function examples(array $content): array
    {
        $examples = [];

        foreach ($content as $type => $entry) {
            if (! isset($entry['example'])) {
                continue;
            }

            $example = $entry['example'];

            $examples[] = [
                'tipo' => $type,
                'exemplo' => is_string($example)
                    ? $example
                    : (string) json_encode(
                        $example,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ),
            ];
        }

        return $examples;
    }

    /**
     * Descreve o schema numa linha: tipo, formato e valores aceitos.
     *
     * @param  array<string, mixed>  $schema
     */
    private function type(array $schema): string
    {
        $type = $schema['type'] ?? 'string';
        $type = is_array($type) ? implode(' | ', $type) : $type;

        if (isset($schema['format'])) {
            $type .= " ({$schema['format']})";
        }

        if (isset($schema['enum'])) {
            $type .= ' — '.implode(', ', $schema['enum']);
        }

        return $type;
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
    private function resolve(array $spec, array $item): array
    {
        if (! isset($item['$ref'])) {
            return $item;
        }

        $target = $spec;

        foreach (explode('/', ltrim($item['$ref'], '#/')) as $segmento) {
            $target = $target[$segmento] ?? [];
        }

        return is_array($target) ? $target : [];
    }
}
