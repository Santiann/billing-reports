<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The documentation has to exist in the HTML, not after the JavaScript.
 *
 * This is the criterion that decided the tool: `curl localhost:8000` has to answer with the
 * documentation. An off-the-shelf spec renderer — Redoc, Scalar, Elements — returns a shell with
 * `<div id="app">` and fetches the rest in the browser, and to curl that is an empty page. Here
 * the page is assembled on the server.
 *
 * The assertions below are the literal reading of that criterion: the method, the route, the
 * parameters and a response example, for every endpoint.
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

    /** Laravel's welcome page must not have survived. */
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
     * The parameters come from three places in the spec — directly on the operation, at the path
     * level, and by $ref into components. The page has to resolve all three, otherwise the period
     * filter simply does not appear.
     */
    public function test_shows_the_parameters_including_the_ones_that_come_by_reference(): void
    {
        $html = $this->get('/')->getContent();

        // Directly on the operation.
        $this->assertStringContainsString('per_page', $html);
        // From the path level.
        $this->assertStringContainsString('billing', $html);
        // By $ref: the report's filters live in components/parameters.
        $this->assertStringContainsString('date_field', $html);
        $this->assertStringContainsString('start_date', $html);
        $this->assertStringContainsString('customer_id', $html);
    }

    public function test_shows_a_response_example(): void
    {
        $html = $this->get('/')->getContent();

        // Values from the billing example, checked against InterestCalculator.
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

    /** The PDF cap's 422 is a design decision and has to be visible. */
    public function test_shows_the_error_codes_including_the_pdf_cap(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('401', $html);
        $this->assertStringContainsString('422', $html);
        $this->assertStringContainsString('limite do PDF', $html);
    }

    /**
     * The raw file is for importing into Postman, Insomnia or a client generator. The page is for
     * reading; the YAML is for using.
     */
    public function test_serves_the_raw_spec_for_download(): void
    {
        $response = $this->get('/openapi.yaml');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/yaml');
        $this->assertStringContainsString('openapi: 3.1.0', $response->getContent());
    }
}
