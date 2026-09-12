<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * A spec só vale se não puder divergir do código.
 *
 * Documentação de API escrita à mão apodrece em silêncio: alguém acrescenta um
 * endpoint, esquece do arquivo, e a partir dali a spec descreve um sistema que
 * não existe mais. Este teste torna esse esquecimento impossível — ele compara
 * as duas direções.
 *
 *   rota registrada sem entrada na spec  -> falha (documentação incompleta)
 *   entrada na spec sem rota registrada  -> falha (documentação fantasma)
 *
 * Não toca o banco: o que está sob teste é o arquivo contra o roteador.
 */
class OpenApiSpecTest extends TestCase
{
    /**
     * Rotas que existem e ficam FORA da spec de propósito.
     *
     * Nenhuma delas é da API de faturamento: são infraestrutura do framework
     * ou a própria página de documentação. A lista é explícita para que uma
     * rota nova não escape por omissão — se aparecer uma que não está aqui
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
     * Os verbos que uma entrada de path pode ter.
     *
     * Um path do OpenAPI também aceita chaves que não são método — `parameters`
     * é a que este arquivo usa, para declarar o `{id}` uma vez só em vez de
     * repetir em cada verbo. Iterar sem esta lista trataria `parameters` como
     * se fosse uma operação HTTP.
     */
    private const VERBOS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /** @return array<string, mixed> */
    private function spec(): array
    {
        $caminho = resource_path('openapi.yaml');

        $this->assertFileExists($caminho, 'A spec OpenAPI não existe.');

        return Yaml::parseFile($caminho);
    }

    /**
     * Toda rota registrada, na forma "MÉTODO /caminho".
     *
     * HEAD e OPTIONS ficam de fora: o Laravel os registra sozinho junto do GET
     * e nenhuma spec os declara.
     *
     * @return array<int, string>
     */
    private function rotasRegistradas(): array
    {
        $rotas = [];

        /** @var RegisteredRoute $rota */
        foreach (Route::getRoutes() as $rota) {
            foreach ($rota->methods() as $metodo) {
                if (in_array($metodo, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $rotas[] = $metodo.' /'.ltrim($rota->uri(), '/');
            }
        }

        return array_values(array_unique($rotas));
    }

    /**
     * Toda operação declarada na spec, na mesma forma.
     *
     * @return array<int, string>
     */
    private function operacoesDaSpec(): array
    {
        $operacoes = [];

        foreach ($this->spec()['paths'] as $caminho => $metodos) {
            foreach (array_keys($metodos) as $metodo) {
                if (in_array($metodo, self::VERBOS, true)) {
                    $operacoes[] = strtoupper($metodo).' '.$caminho;
                }
            }
        }

        return $operacoes;
    }

    /**
     * Cada operação da spec, como [rótulo, corpo da operação].
     *
     * @return array<int, array{0: string, 1: array<string, mixed>}>
     */
    private function operacoes(): array
    {
        $operacoes = [];

        foreach ($this->spec()['paths'] as $caminho => $metodos) {
            foreach ($metodos as $metodo => $operacao) {
                if (in_array($metodo, self::VERBOS, true)) {
                    $operacoes[] = [strtoupper($metodo).' '.$caminho, $operacao];
                }
            }
        }

        return $operacoes;
    }

    public function test_toda_rota_registrada_esta_na_spec(): void
    {
        $documentadas = $this->operacoesDaSpec();

        $faltando = array_diff(
            $this->rotasRegistradas(),
            $documentadas,
            array_keys(self::FORA_DA_SPEC),
        );

        $this->assertSame([], array_values($faltando), sprintf(
            "Rota registrada e não documentada:\n  %s\n"
            .'Documente na spec, ou declare em FORA_DA_SPEC por que ela não entra.',
            implode("\n  ", $faltando),
        ));
    }

    public function test_toda_operacao_da_spec_existe_como_rota(): void
    {
        $sobrando = array_diff($this->operacoesDaSpec(), $this->rotasRegistradas());

        $this->assertSame([], array_values($sobrando), sprintf(
            "A spec documenta o que não existe:\n  %s",
            implode("\n  ", $sobrando),
        ));
    }

    public function test_a_spec_declara_openapi_3_1(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.1.0', $spec['openapi'] ?? null);
        $this->assertNotEmpty($spec['info']['title'] ?? null);
        $this->assertNotEmpty($spec['info']['version'] ?? null);
    }

    /**
     * Endpoint sem resposta declarada é entrada de índice, não documentação.
     */
    public function test_toda_operacao_declara_respostas(): void
    {
        foreach ($this->operacoes() as [$onde, $operacao]) {
            $this->assertNotEmpty($operacao['summary'] ?? null, "{$onde} sem summary.");
            $this->assertNotEmpty($operacao['responses'] ?? null, "{$onde} sem respostas.");
            $this->assertNotEmpty(
                $operacao['tags'] ?? null,
                "{$onde} sem tag: o renderizador agruparia solto.",
            );
        }
    }

    /**
     * O 401 é a resposta mais provável de quem experimenta a API pela primeira
     * vez, e a que mais confunde se não estiver documentada.
     */
    public function test_operacao_autenticada_documenta_o_401(): void
    {
        foreach ($this->operacoes() as [$onde, $operacao]) {
            // `security: []` declara operação pública, como o login.
            if (($operacao['security'] ?? null) === []) {
                continue;
            }

            $this->assertArrayHasKey(
                401,
                $operacao['responses'],
                "{$onde} é autenticada e não documenta o 401.",
            );
        }
    }

    /**
     * O 422 do teto do PDF é uma decisão de projeto, não um erro acidental:
     * acima do limite a API recusa e orienta o CSV. Documentá-lo é o que
     * impede alguém de tratar como bug.
     */
    public function test_o_teto_do_pdf_esta_documentado(): void
    {
        $operacao = $this->spec()['paths']['/api/reports/billings/pdf']['get'];

        $this->assertArrayHasKey(422, $operacao['responses']);

        $exemplo = $operacao['responses'][422]['content']['application/json']['example'] ?? [];

        $this->assertArrayHasKey('limit', $exemplo, 'O 422 do PDF precisa mostrar o limite.');
        $this->assertArrayHasKey('count', $exemplo, 'O 422 do PDF precisa mostrar a contagem.');
        $this->assertSame(
            config('reports.pdf_max_rows'),
            $exemplo['limit'],
            'O limite do exemplo divergiu de config/reports.php.',
        );
    }

    /**
     * Exemplo é o que transforma a spec em documentação utilizável: sem ele,
     * quem lê fica com o formato e sem a forma do dado.
     */
    public function test_as_respostas_de_sucesso_trazem_exemplo(): void
    {
        foreach ($this->operacoes() as [$onde, $operacao]) {
            foreach ($operacao['responses'] as $status => $resposta) {
                if ($status < 200 || $status >= 300 || ! isset($resposta['content'])) {
                    continue;
                }

                foreach ($resposta['content'] as $tipo => $conteudo) {
                    // Binário não tem exemplo em JSON: o PDF declara o formato,
                    // e é o que há para declarar.
                    if (! str_contains($tipo, 'json')) {
                        continue;
                    }

                    $this->assertTrue(
                        isset($conteudo['example']) || isset($conteudo['examples']),
                        "{$onde} responde {$status} sem exemplo.",
                    );
                }
            }
        }
    }
}
