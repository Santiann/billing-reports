<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * A documentação precisa existir no HTML, não depois do JavaScript.
 *
 * Este é o critério que decidiu a ferramenta: `curl localhost:8000` tem que
 * responder a documentação. Renderizador de spec do mercado — Redoc, Scalar,
 * Elements — devolve uma casca com `<div id="app">` e busca o resto no browser,
 * e para o curl isso é uma página vazia. Aqui a página é montada no servidor.
 *
 * As asserções abaixo são a leitura literal do critério: método, rota,
 * parâmetros e exemplo de resposta, de todos os endpoints.
 */
class ApiDocumentationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function spec(): array
    {
        return Yaml::parseFile(resource_path('openapi.yaml'));
    }

    public function test_the_root_serves_the_documentation_without_authentication(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/html; charset=UTF-8');
        $response->assertSee('Gerador de Relatórios', false);
    }

    /** A welcome do Laravel não pode ter sobrado. */
    public function test_the_root_is_no_longer_the_laravel_page(): void
    {
        $this->get('/')->assertDontSee('Laravel has an incredibly rich ecosystem', false);
    }

    public function test_lists_the_route_and_method_of_every_endpoint(): void
    {
        $html = $this->get('/')->getContent();

        foreach ($this->spec()['paths'] as $path => $metodos) {
            $this->assertStringContainsString(
                $path,
                $html,
                "A documentação não lista a rota {$path}.",
            );

            foreach ($metodos as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }

                $this->assertStringContainsString(
                    strtoupper($method),
                    $html,
                    "A documentação não lista o método {$method} de {$path}.",
                );

                $this->assertStringContainsString(
                    e($operation['summary']),
                    $html,
                    "A documentação não traz o resumo de {$method} {$path}.",
                );
            }
        }
    }

    /**
     * Os parâmetros vêm de três lugares na spec — direto na operação, no nível
     * do path, e por $ref para components. A página tem que resolver os três,
     * senão o filtro de período simplesmente não aparece.
     */
    public function test_shows_the_parameters_including_the_ones_that_come_by_reference(): void
    {
        $html = $this->get('/')->getContent();

        // Direto na operação.
        $this->assertStringContainsString('per_page', $html);
        // Do nível do path.
        $this->assertStringContainsString('billing', $html);
        // Por $ref: os filtros do relatório moram em components/parameters.
        $this->assertStringContainsString('date_field', $html);
        $this->assertStringContainsString('start_date', $html);
        $this->assertStringContainsString('customer_id', $html);
    }

    public function test_shows_a_response_example(): void
    {
        $html = $this->get('/')->getContent();

        // Valores do exemplo de cobrança, conferidos contra o InterestCalculator.
        $this->assertStringContainsString('updated_amount', $html);
        $this->assertStringContainsString('1534.05', $html);
        $this->assertStringContainsString('paid_interest_amount', $html);
    }

    public function test_shows_the_expected_request_body(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('monthly_interest_rate', $html);
        $this->assertStringContainsString('admin@billing.test', $html);
    }

    /** O 422 do teto do PDF é decisão de projeto e precisa estar visível. */
    public function test_shows_the_error_codes_including_the_pdf_cap(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('401', $html);
        $this->assertStringContainsString('422', $html);
        $this->assertStringContainsString('limite do PDF', $html);
    }

    /**
     * O arquivo cru serve para importar em Postman, Insomnia ou num gerador de
     * cliente. A página é para ler; o YAML é para usar.
     */
    public function test_serves_the_raw_spec_for_download(): void
    {
        $response = $this->get('/openapi.yaml');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/yaml');
        $this->assertStringContainsString('openapi: 3.1.0', $response->getContent());
    }
}
