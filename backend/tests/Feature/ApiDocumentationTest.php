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

    public function test_a_raiz_responde_a_documentacao_sem_autenticacao(): void
    {
        $resposta = $this->get('/');

        $resposta->assertOk();
        $resposta->assertHeader('content-type', 'text/html; charset=UTF-8');
        $resposta->assertSee('Gerador de Relatórios', false);
    }

    /** A welcome do Laravel não pode ter sobrado. */
    public function test_a_raiz_nao_e_mais_a_pagina_do_laravel(): void
    {
        $this->get('/')->assertDontSee('Laravel has an incredibly rich ecosystem', false);
    }

    public function test_lista_a_rota_e_o_metodo_de_todos_os_endpoints(): void
    {
        $html = $this->get('/')->getContent();

        foreach ($this->spec()['paths'] as $caminho => $metodos) {
            $this->assertStringContainsString(
                $caminho,
                $html,
                "A documentação não lista a rota {$caminho}.",
            );

            foreach ($metodos as $metodo => $operacao) {
                if ($metodo === 'parameters') {
                    continue;
                }

                $this->assertStringContainsString(
                    strtoupper($metodo),
                    $html,
                    "A documentação não lista o método {$metodo} de {$caminho}.",
                );

                $this->assertStringContainsString(
                    e($operacao['summary']),
                    $html,
                    "A documentação não traz o resumo de {$metodo} {$caminho}.",
                );
            }
        }
    }

    /**
     * Os parâmetros vêm de três lugares na spec — direto na operação, no nível
     * do path, e por $ref para components. A página tem que resolver os três,
     * senão o filtro de período simplesmente não aparece.
     */
    public function test_mostra_os_parametros_inclusive_os_que_vem_por_referencia(): void
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

    public function test_mostra_exemplo_de_resposta(): void
    {
        $html = $this->get('/')->getContent();

        // Valores do exemplo de cobrança, conferidos contra o InterestCalculator.
        $this->assertStringContainsString('updated_amount', $html);
        $this->assertStringContainsString('1534.05', $html);
        $this->assertStringContainsString('paid_interest_amount', $html);
    }

    public function test_mostra_o_corpo_esperado_na_requisicao(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('monthly_interest_rate', $html);
        $this->assertStringContainsString('admin@billing.test', $html);
    }

    /** O 422 do teto do PDF é decisão de projeto e precisa estar visível. */
    public function test_mostra_os_codigos_de_erro_com_o_teto_do_pdf(): void
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
    public function test_serve_a_spec_crua_para_download(): void
    {
        $resposta = $this->get('/openapi.yaml');

        $resposta->assertOk();
        $resposta->assertHeader('content-type', 'application/yaml');
        $this->assertStringContainsString('openapi: 3.1.0', $resposta->getContent());
    }
}
