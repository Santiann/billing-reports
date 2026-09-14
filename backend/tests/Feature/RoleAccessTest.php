<?php

namespace Tests\Feature;

use App\Domain\User\UserRole;
use App\Models\Billing;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Perfis de acesso: administrador e consulta.
 *
 * A regra é simples e a proteção é no BACKEND. Esconder o botão na tela é
 * conveniência para quem não pode usá-lo, nunca a barreira: quem sabe o
 * endereço do endpoint chega nele sem passar por tela nenhuma.
 *
 * Por isso estes testes batem direto na API, e cobrem todo endpoint que
 * escreve. Um endpoint de escrita novo sem entrada aqui é um buraco.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function comoConsulta(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer]));
    }

    private function comoAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    private function comIds(string $rota, Billing $cobranca): string
    {
        return str_replace(
            ['{cliente}', '{cobranca}'],
            [(string) $cobranca->customer_id, (string) $cobranca->id],
            $rota,
        );
    }

    /**
     * Todo endpoint que escreve, com um payload qualquer: o que se afirma aqui
     * é o 403, não a validação.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpointsDeEscrita(): array
    {
        // {cliente} e {cobranca} viram os ids reais no teste: id fixo falharia
        // com 404 antes de chegar ao 403, porque o auto-increment não reinicia
        // entre os testes.
        return [
            'criar cliente' => ['post', '/api/customers'],
            'editar cliente' => ['put', '/api/customers/{cliente}'],
            'importar clientes' => ['post', '/api/customers/import'],
            'criar cobrança' => ['post', '/api/billings'],
            'editar cobrança' => ['put', '/api/billings/{cobranca}'],
            'importar cobranças' => ['post', '/api/billings/import'],
            'registrar pagamento' => ['post', '/api/billings/{cobranca}/payment'],
        ];
    }

    #[DataProvider('endpointsDeEscrita')]
    public function test_consulta_nao_escreve(string $metodo, string $rota): void
    {
        $this->comoConsulta();

        // Os registros existem para o 403 não se confundir com um 404.
        $cobranca = Billing::factory()->create();

        $this->json($metodo, $this->comIds($rota, $cobranca), [])->assertForbidden();
    }

    /**
     * A outra metade do par. Só o 403 não provaria nada — provaria o mesmo se
     * a rota estivesse quebrada para todo mundo.
     */
    public function test_admin_escreve(): void
    {
        $this->comoAdmin();
        $cliente = Customer::factory()->create();

        $this->postJson('/api/customers', [
            'name' => 'Comércio Silva LTDA',
            'document' => '12345678000190',
            'email' => 'financeiro@silva.test',
            'status' => 'active',
        ])->assertCreated();

        $this->putJson("/api/customers/{$cliente->id}", [
            'name' => 'Outro Nome LTDA',
            'document' => $cliente->document,
            'email' => $cliente->email,
            'status' => 'active',
        ])->assertOk();
    }

    // --- leitura ------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function endpointsDeLeitura(): array
    {
        return [
            'listar clientes' => ['/api/customers'],
            'exibir cliente' => ['/api/customers/{cliente}'],
            'listar cobranças' => ['/api/billings'],
            'exibir cobrança' => ['/api/billings/{cobranca}'],
            'trilha da cobrança' => ['/api/billings/{cobranca}/audit'],
            'relatório' => ['/api/reports/billings'],
            'dashboard' => ['/api/dashboard'],
        ];
    }

    #[DataProvider('endpointsDeLeitura')]
    public function test_consulta_le_tudo(string $rota): void
    {
        $this->comoConsulta();
        $cobranca = Billing::factory()->create();

        $this->getJson($this->comIds($rota, $cobranca))->assertOk();
    }

    /** Exportar é leitura: o arquivo é o mesmo relatório em outro formato. */
    public function test_consulta_exporta(): void
    {
        $this->comoConsulta();
        Billing::factory()->create();

        $this->get('/api/reports/billings/csv')->assertOk();
    }

    // --- a identidade do perfil ---------------------------------------

    public function test_a_sessao_informa_o_perfil(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => UserRole::Viewer,
            'name' => 'Fulano',
        ]));

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.role', 'viewer')
            ->assertJsonPath('user.role_label', 'Consulta');
    }

    /**
     * Sem perfil declarado, o usuário é de consulta.
     *
     * O default é o menor privilégio de propósito: um usuário criado por
     * caminho que esqueceu de definir o perfil não pode sair escrevendo.
     */
    public function test_o_perfil_padrao_e_o_de_menor_privilegio(): void
    {
        $usuario = User::query()->create([
            'name' => 'Sem perfil',
            'email' => 'sem-perfil@exemplo.test',
            'password' => 'password',
        ]);

        $this->assertSame(UserRole::Viewer, $usuario->fresh()->role);
    }

    /** Sem token, o 401 continua vindo antes do 403. */
    public function test_sem_sessao_continua_401_e_nao_403(): void
    {
        $this->postJson('/api/customers', [])->assertUnauthorized();
    }

    /**
     * O 403 precisa explicar. Uma resposta vazia manda o usuário achar que o
     * sistema quebrou.
     */
    public function test_a_recusa_explica_o_motivo(): void
    {
        $this->comoConsulta();

        $this->postJson('/api/customers', [])
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'consulta'));
    }

    public function test_consulta_nao_importa_arquivo(): void
    {
        $this->comoConsulta();

        $this->postJson('/api/customers/import', [
            'file' => UploadedFile::fake()->createWithContent('c.csv', "nome;documento;email\n"),
        ])->assertForbidden();

        $this->assertSame(0, Customer::query()->count());
    }
}
