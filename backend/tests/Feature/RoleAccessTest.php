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
 * Access roles: administrator and read-only.
 *
 * The rule is simple and the protection is in the BACKEND. Hiding the button on screen is a
 * convenience for whoever cannot use it, never the barrier: whoever knows the endpoint's address
 * reaches it without passing through any screen.
 *
 * That is why these tests hit the API directly, and cover every endpoint that writes. A new write
 * endpoint with no entry here is a hole.
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

    private function comIds(string $rota, Billing $billing): string
    {
        return str_replace(
            ['{customer}', '{billing}'],
            [(string) $billing->customer_id, (string) $billing->id],
            $rota,
        );
    }

    /**
     * Every endpoint that writes, with any payload at all: what gets asserted here is the 403,
     * not the validation.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpointsDeEscrita(): array
    {
        // {customer} and {billing} become the real ids in the test: a fixed id would fail with a
        // 404 before reaching the 403, because the auto-increment does not restart
        // entre os testes.
        return [
            'create customer' => ['post', '/api/customers'],
            'edit customer' => ['put', '/api/customers/{customer}'],
            'import customers' => ['post', '/api/customers/import'],
            'create billing' => ['post', '/api/billings'],
            'edit billing' => ['put', '/api/billings/{billing}'],
            'import billings' => ['post', '/api/billings/import'],
            'record payment' => ['post', '/api/billings/{billing}/payment'],
            'reverse payment' => ['post', '/api/billings/{billing}/reversal'],
        ];
    }

    #[DataProvider('endpointsDeEscrita')]
    public function test_the_read_only_role_cannot_write(string $method, string $rota): void
    {
        $this->comoConsulta();

        // The records exist so the 403 cannot be confused with a 404.
        $billing = Billing::factory()->create();

        $this->json($method, $this->comIds($rota, $billing), [])->assertForbidden();
    }

    /**
     * The other half of the pair. The 403 alone would prove nothing — it would prove the same if
     * the route were broken for everyone.
     */
    public function test_the_admin_role_can_write(): void
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
            'list customers' => ['/api/customers'],
            'show customer' => ['/api/customers/{customer}'],
            'list billings' => ['/api/billings'],
            'show billing' => ['/api/billings/{billing}'],
            'billing trail' => ['/api/billings/{billing}/audit'],
            'report' => ['/api/reports/billings'],
            'dashboard' => ['/api/dashboard'],
        ];
    }

    #[DataProvider('endpointsDeLeitura')]
    public function test_the_read_only_role_reads_everything(string $rota): void
    {
        $this->comoConsulta();
        $billing = Billing::factory()->create();

        $this->getJson($this->comIds($rota, $billing))->assertOk();
    }

    /** Exporting is reading: the file is the same report in another format. */
    public function test_the_read_only_role_can_export(): void
    {
        $this->comoConsulta();
        Billing::factory()->create();

        $this->get('/api/reports/billings/csv')->assertOk();
    }

    // --- the role's identity ------------------------------------------

    public function test_the_session_reports_the_role(): void
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
     * With no role declared, the user is read-only.
     *
     * The default is the least privilege on purpose: a user created through a path that forgot to
     * set the role must not go off writing.
     */
    public function test_the_default_role_is_the_least_privileged(): void
    {
        $user = User::query()->create([
            'name' => 'Sem perfil',
            'email' => 'sem-perfil@exemplo.test',
            'password' => 'password',
        ]);

        $this->assertSame(UserRole::Viewer, $user->fresh()->role);
    }

    /** With no token, the 401 still comes before the 403. */
    public function test_with_no_session_it_is_still_401_and_not_403(): void
    {
        $this->postJson('/api/customers', [])->assertUnauthorized();
    }

    /**
     * The 403 has to explain. An empty response leaves the user thinking the
     * sistema quebrou.
     */
    public function test_the_refusal_explains_why(): void
    {
        $this->comoConsulta();

        $this->postJson('/api/customers', [])
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'consulta'));
    }

    public function test_the_read_only_role_cannot_import_a_file(): void
    {
        $this->comoConsulta();

        $this->postJson('/api/customers/import', [
            'file' => UploadedFile::fake()->createWithContent('c.csv', "nome;documento;email\n"),
        ])->assertForbidden();

        $this->assertSame(0, Customer::query()->count());
    }
}
