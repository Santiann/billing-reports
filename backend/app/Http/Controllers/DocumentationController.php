<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\Yaml\Yaml;

/**
 * The API documentation, assembled on the server.
 *
 * An off-the-shelf spec renderer — Redoc, Scalar, Elements — solves this with one line of
 * HTML and a script tag, and it looks good. But all of them build the page in the browser,
 * and `curl localhost:8000` would return `<div id="app">` and nothing else. The
 * documentation has to exist in the response, not after the JavaScript: that is what makes
 * it readable from a terminal, indexable, and openable with no internet.
 *
 * The cost of that choice is this file: resolving `$ref`, merging the path's parameters
 * with the operation's, and formatting examples. In return, the page answers `curl` and
 * depends on no CDN.
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
     * The raw YAML, to import into Postman, Insomnia or a client generator.
     *
     * The page is for reading; the file is for using.
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
     * No cache, on purpose.
     *
     * Parsing takes a few milliseconds and this is nobody's hot path. In exchange, editing
     * the spec and reloading shows the result immediately — which is what you want from a
     * hand-maintained file.
     *
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        return Yaml::parseFile($this->path());
    }

    /**
     * Reorganises the endpoints by tag, in the order the tags appear.
     *
     * The spec is indexed by path because the format demands it; whoever reads looks by
     * subject. Parameters declared at the path level are merged into the operation's,
     * because for a reader the distinction does not exist: they are all parameters of that
     * call.
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
                    // `security: []` on the operation declares a public route.
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
     * An example already formatted for reading, per content type.
     *
     * The CSV example is a string and comes out as is; the JSON ones are structures and come
     * out indented. `JSON_UNESCAPED_UNICODE` because the API answers in Portuguese and
     * `\u00e9` in place of an accented letter is not an example, it is a riddle.
     *
     * @param  array<string, mixed>  $content
     * @return array<int, array{type: string, example: string}>
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
     * Describes the schema in one line: type, format and accepted values.
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
     * Follows a `$ref` to the object it points at.
     *
     * The spec uses references so the 401 is not repeated in fifteen places. Whoever reads
     * the page needs to see the content, not the pointer.
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
