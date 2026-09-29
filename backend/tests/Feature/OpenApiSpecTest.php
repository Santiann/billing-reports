<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The spec is only worth anything if it cannot drift from the code.
 *
 * Hand-written API documentation rots in silence: someone adds an endpoint, forgets the file,
 * and from then on the spec describes a system that no longer exists. This test makes that
 * forgetting impossible — it compares both directions.
 *
 *   a registered route with no entry in the spec  -> fails (incomplete documentation)
 *   an entry in the spec with no registered route -> fails (phantom documentation)
 *
 * It does not touch the database: what is under test is the file against the router.
 */
class OpenApiSpecTest extends TestCase
{
    /**
     * Routes that exist and are deliberately OUTSIDE the spec.
     *
     * None of them belongs to the billing API: they are framework infrastructure or the
     * documentation page itself. The list is explicit so a new route cannot slip through by
     * omission — if one shows up that is not here
     * nem na spec, o teste falha e alguém precisa decidir.
     */
    private const FORA_DA_SPEC = [
        'GET /' => 'A própria documentação, servida na raiz.',
        'GET /openapi.yaml' => 'O arquivo desta spec, servido cru para importar em cliente de API.',
        'GET /up' => 'Health check do próprio Laravel.',
        'GET /sanctum/csrf-cookie' => 'Rota do Sanctum para o fluxo de SPA com cookie, não usada aqui.',
        'GET /storage/{path}' => 'Servidor de arquivos do disco público.',
        'PUT /storage/{path}' => 'Servidor de arquivos do disco público.',
    ];

    /**
     * The verbs a path entry can have.
     *
     * An OpenAPI path also accepts keys that are not methods — `parameters` is the one this
     * file uses, to declare `{id}` once instead of repeating it on every verb. Iterating
     * without this list would treat `parameters` as if it were an HTTP operation.
     */
    private const VERBOS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /** @return array<string, mixed> */
    private function spec(): array
    {
        $path = resource_path('openapi.yaml');

        $this->assertFileExists($path, 'A spec OpenAPI não existe.');

        return Yaml::parseFile($path);
    }

    /**
     * Every registered route, in the form "METHOD /path".
     *
     * HEAD and OPTIONS are left out: Laravel registers them on its own alongside GET and no
     * spec declares them.
     *
     * @return array<int, string>
     */
    private function rotasRegistradas(): array
    {
        $rotas = [];

        /** @var RegisteredRoute $rota */
        foreach (Route::getRoutes() as $rota) {
            foreach ($rota->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $rotas[] = $method.' /'.ltrim($rota->uri(), '/');
            }
        }

        return array_values(array_unique($rotas));
    }

    /**
     * Every operation declared in the spec, in the same form.
     *
     * @return array<int, string>
     */
    private function operacoesDaSpec(): array
    {
        $operacoes = [];

        foreach ($this->spec()['paths'] as $path => $metodos) {
            foreach (array_keys($metodos) as $method) {
                if (in_array($method, self::VERBOS, true)) {
                    $operacoes[] = strtoupper($method).' '.$path;
                }
            }
        }

        return $operacoes;
    }

    /**
     * Each of the spec's operations, as [label, operation body].
     *
     * @return array<int, array{0: string, 1: array<string, mixed>}>
     */
    private function operacoes(): array
    {
        $operacoes = [];

        foreach ($this->spec()['paths'] as $path => $metodos) {
            foreach ($metodos as $method => $operation) {
                if (in_array($method, self::VERBOS, true)) {
                    $operacoes[] = [strtoupper($method).' '.$path, $operation];
                }
            }
        }

        return $operacoes;
    }

    public function test_every_registered_route_is_in_the_spec(): void
    {
        $documentadas = $this->operacoesDaSpec();

        $missing = array_diff(
            $this->rotasRegistradas(),
            $documentadas,
            array_keys(self::FORA_DA_SPEC),
        );

        $this->assertSame([], array_values($missing), sprintf(
            "Rota registrada e não documentada:\n  %s\n"
            .'Documente na spec, ou declare em FORA_DA_SPEC por que ela não entra.',
            implode("\n  ", $missing),
        ));
    }

    public function test_every_operation_in_the_spec_exists_as_a_route(): void
    {
        $sobrando = array_diff($this->operacoesDaSpec(), $this->rotasRegistradas());

        $this->assertSame([], array_values($sobrando), sprintf(
            "A spec documenta o que não existe:\n  %s",
            implode("\n  ", $sobrando),
        ));
    }

    public function test_the_spec_declares_openapi_3_1(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.1.0', $spec['openapi'] ?? null);
        $this->assertNotEmpty($spec['info']['title'] ?? null);
        $this->assertNotEmpty($spec['info']['version'] ?? null);
    }

    /**
     * An endpoint with no declared response is an index entry, not documentation.
     */
    public function test_every_operation_declares_responses(): void
    {
        foreach ($this->operacoes() as [$onde, $operation]) {
            $this->assertNotEmpty($operation['summary'] ?? null, "{$onde} sem summary.");
            $this->assertNotEmpty($operation['responses'] ?? null, "{$onde} sem respostas.");
            $this->assertNotEmpty(
                $operation['tags'] ?? null,
                "{$onde} sem tag: o renderizador agruparia solto.",
            );
        }
    }

    /**
     * The 401 is the most likely response for someone trying the API for the first time, and
     * the most confusing one if it is not documented.
     */
    public function test_an_authenticated_operation_documents_the_401(): void
    {
        foreach ($this->operacoes() as [$onde, $operation]) {
            // `security: []` declares a public operation, such as the login.
            if (($operation['security'] ?? null) === []) {
                continue;
            }

            $this->assertArrayHasKey(
                401,
                $operation['responses'],
                "{$onde} é autenticada e não documenta o 401.",
            );
        }
    }

    /**
     * The PDF cap's 422 is a design decision, not an accidental error: above the limit the API
     * refuses and points at the CSV. Documenting it is what stops someone treating it as a
     * bug.
     */
    public function test_the_pdf_cap_is_documented(): void
    {
        $operation = $this->spec()['paths']['/api/reports/billings/pdf']['get'];

        $this->assertArrayHasKey(422, $operation['responses']);

        $example = $operation['responses'][422]['content']['application/json']['example'] ?? [];

        $this->assertArrayHasKey('limit', $example, 'O 422 do PDF precisa mostrar o limite.');
        $this->assertArrayHasKey('count', $example, 'O 422 do PDF precisa mostrar a contagem.');
        $this->assertSame(
            config('reports.pdf_max_rows'),
            $example['limit'],
            'O limite do exemplo divergiu de config/reports.php.',
        );
    }

    /**
     * An example is what turns the spec into usable documentation: without one, the reader is
     * left with the format and none of the data's shape.
     */
    public function test_the_success_responses_carry_an_example(): void
    {
        foreach ($this->operacoes() as [$onde, $operation]) {
            foreach ($operation['responses'] as $status => $response) {
                if ($status < 200 || $status >= 300 || ! isset($response['content'])) {
                    continue;
                }

                foreach ($response['content'] as $type => $content) {
                    // A binary has no JSON example: the PDF declares the format, and that is
                    // all there is to declare.
                    if (! str_contains($type, 'json')) {
                        continue;
                    }

                    $this->assertTrue(
                        isset($content['example']) || isset($content['examples']),
                        "{$onde} responde {$status} sem exemplo.",
                    );
                }
            }
        }
    }
}
