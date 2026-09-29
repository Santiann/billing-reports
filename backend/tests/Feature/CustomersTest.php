<?php

namespace Tests\Feature;

use App\Domain\Customer\CustomerStatus;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomersTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    // --- protection ---------------------------------------------------

    public function test_the_listing_requires_authentication(): void
    {
        $this->getJson('/api/customers')->assertUnauthorized();
    }

    public function test_the_listing_responds_to_an_authenticated_user(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/customers')->assertOk();
    }

    public function test_creating_requires_authentication(): void
    {
        $this->postJson('/api/customers', [])->assertUnauthorized();
    }

    // --- listagem -----------------------------------------------------

    public function test_the_listing_paginates_in_the_database(): void
    {
        $this->actingAsUser();
        Customer::factory()->count(25)->create();

        $response = $this->getJson('/api/customers?per_page=10')->assertOk();

        // The page brings 10 records, but the total knows all 25: proof the narrowing is the
        // database's and not a fully loaded collection's.
        $this->assertCount(10, $response->json('data'));
        $this->assertSame(25, $response->json('meta.total'));
        $this->assertSame(10, $response->json('meta.per_page'));
    }

    public function test_the_second_page_brings_different_records(): void
    {
        $this->actingAsUser();
        Customer::factory()->count(25)->create();

        $firstOne = $this->getJson('/api/customers?per_page=10&page=1')->json('data.*.id');
        $segunda = $this->getJson('/api/customers?per_page=10&page=2')->json('data.*.id');

        $this->assertEmpty(array_intersect($firstOne, $segunda));
    }

    public function test_per_page_is_capped(): void
    {
        $this->actingAsUser();

        // Without a cap, ?per_page=999999 would be a trivial way to take the API down.
        $this->getJson('/api/customers?per_page=100000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_filters_by_status(): void
    {
        $this->actingAsUser();
        Customer::factory()->count(3)->create();
        Customer::factory()->inactive()->count(2)->create();

        $response = $this->getJson('/api/customers?status=inactive')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_searches_by_name_document_and_email(): void
    {
        $this->actingAsUser();
        Customer::factory()->create([
            'name' => 'Padaria Aurora',
            'document' => '99988877766',
            'email' => 'contato@aurora.test',
        ]);
        Customer::factory()->count(4)->create();

        foreach (['Aurora', '99988877766', 'contato@aurora'] as $termo) {
            $response = $this->getJson('/api/customers?search='.urlencode($termo))->assertOk();

            $this->assertSame(1, $response->json('meta.total'), "busca falhou para: {$termo}");
            $this->assertSame('Padaria Aurora', $response->json('data.0.name'));
        }
    }

    public function test_sorts_by_an_allowed_column(): void
    {
        $this->actingAsUser();
        Customer::factory()->create(['name' => 'Zebra']);
        Customer::factory()->create(['name' => 'Abelha']);

        $nomes = $this->getJson('/api/customers?sort=name&direction=asc')
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame('Abelha', $nomes[0]);
    }

    public function test_sorting_by_an_arbitrary_column_is_rejected(): void
    {
        $this->actingAsUser();

        // Without an allowlist, the parameter would go raw into the ORDER BY.
        $this->getJson('/api/customers?sort=password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');
    }

    // --- cadastro -----------------------------------------------------

    public function test_creates_a_customer(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers', [
            'name' => 'Mercado Central',
            'document' => '12345678901',
            'email' => 'contato@central.test',
            'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.name', 'Mercado Central');

        $this->assertDatabaseHas('customers', ['document' => '12345678901']);
    }

    public function test_the_document_is_stored_unformatted(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers', [
            'name' => 'Mercado Central',
            'document' => '123.456.789-01',
            'email' => 'contato@central.test',
            'status' => 'active',
        ])->assertCreated();

        // Storing it formatted would make the search depend on how it was typed.
        $this->assertDatabaseHas('customers', ['document' => '12345678901']);
    }

    public function test_a_duplicate_document_is_rejected(): void
    {
        $this->actingAsUser();
        Customer::factory()->create(['document' => '12345678901']);

        $this->postJson('/api/customers', [
            'name' => 'Outro',
            'document' => '12345678901',
            'email' => 'outro@test.test',
            'status' => 'active',
        ])->assertUnprocessable()->assertJsonValidationErrors('document');
    }

    public function test_creating_validates_the_required_fields(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/customers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'document', 'email', 'status']);
    }

    // --- viewing and editing -----------------------------------------

    public function test_shows_a_customer(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create(['name' => 'Acme']);

        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme');
    }

    public function test_a_missing_customer_responds_404(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/customers/999999')->assertNotFound();
    }

    public function test_edits_a_customer(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create(['name' => 'Nome Antigo']);

        $this->putJson("/api/customers/{$customer->id}", [
            'name' => 'Nome Novo',
            'document' => $customer->document,
            'email' => $customer->email,
            'status' => CustomerStatus::Inactive->value,
        ])->assertOk()->assertJsonPath('data.name', 'Nome Novo');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Nome Novo',
            'status' => 'inactive',
        ]);
    }

    public function test_editing_does_not_conflict_with_its_own_document(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create(['document' => '12345678901']);

        // The unique rule has to ignore the record itself, otherwise nobody can save an edit
        // without changing the document.
        $this->putJson("/api/customers/{$customer->id}", [
            'name' => 'Nome Novo',
            'document' => '12345678901',
            'email' => $customer->email,
            'status' => 'active',
        ])->assertOk();
    }
}
