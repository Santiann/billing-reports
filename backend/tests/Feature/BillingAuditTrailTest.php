<?php

namespace Tests\Feature;

use App\Domain\User\UserRole;
use App\Models\Billing;
use App\Models\BillingAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use LogicException;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The billings' audit trail: who changed what, and when.
 *
 * A trail is only worth what it guarantees, and there are three guarantees:
 *
 *   complete  -> every change gets in, including one arriving by a new path
 *   atomic    -> without a record in the trail, the change does not happen
 *   immutable -> what was recorded cannot be edited nor deleted
 *
 * Creation is deliberately left out — the README explains why. What gets in is what the brief
 * asks for: edits, payments and, in the following commit, reversals.
 */
class BillingAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private const AGORA = '2026-06-15 09:30:00';

    private function comoUsuario(string $nome = 'Marina Costa', UserRole $perfil = UserRole::Admin): User
    {
        $user = User::factory()->create(['name' => $nome, 'role' => $perfil]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function billing(): Billing
    {
        // Vencida há 30 dias a 2% ao mês: pagar hoje dá 1.020,00.
        return Billing::factory()->create([
            'description' => 'Mensalidade de maio',
            'original_amount' => '1000.00',
            'monthly_interest_rate' => '0.0200',
            'issue_date' => '2026-04-16',
            'due_date' => '2026-05-16',
        ]);
    }

    /** @param array<string, mixed> $changes */
    private function editar(Billing $billing, array $changes)
    {
        return $this->putJson("/api/billings/{$billing->id}", [
            'customer_id' => $billing->customer_id,
            'description' => $billing->description,
            'original_amount' => $billing->original_amount,
            'monthly_interest_rate' => $billing->monthly_interest_rate,
            'issue_date' => $billing->issue_date->toDateString(),
            'due_date' => $billing->due_date->toDateString(),
            ...$changes,
        ]);
    }

    /**
     * Compares the stored changes without depending on the order of the keys.
     *
     * A MySQL JSON column reorders keys by size — `{"from", "to"}` comes back as
     * `{"to", "from"}`. The order the API delivers is imposed by the resource; what gets
     * asserted here is the content. `assertEquals` would solve the ordering, but it would
     * accept `null` as equal to `''`, and the payment's null `from` is precisely what
     * matters.
     *
     * @param  array<string, mixed>  $esperado
     * @param  array<string, mixed>  $gravado
     */
    private function assertMudancas(array $esperado, array $gravado): void
    {
        $ordenar = function (array $values) use (&$ordenar): array {
            ksort($values);

            return array_map(fn ($v) => is_array($v) ? $ordenar($v) : $v, $values);
        };

        $this->assertSame($ordenar($esperado), $ordenar($gravado));
    }

    /** @return Collection<int, BillingAudit> */
    private function trilha(Billing $billing): Collection
    {
        return BillingAudit::query()
            ->where('billing_id', $billing->id)
            ->orderBy('id')
            ->get();
    }

    // --- edits --------------------------------------------------------

    public function test_an_edit_records_who_what_and_when(): void
    {
        $this->travelTo(self::AGORA);
        $user = $this->comoUsuario();
        $billing = $this->billing();

        $this->editar($billing, [
            'description' => 'Mensalidade de maio — corrigida',
            'due_date' => '2026-05-20',
        ])->assertOk();

        $trilha = $this->trilha($billing);

        $this->assertCount(1, $trilha);
        $this->assertSame('updated', $trilha[0]->event->value);
        $this->assertSame($user->id, $trilha[0]->user_id);
        $this->assertSame(self::AGORA, $trilha[0]->created_at->toDateTimeString());
        $this->assertMudancas([
            'description' => ['from' => 'Mensalidade de maio', 'to' => 'Mensalidade de maio — corrigida'],
            'due_date' => ['from' => '2026-05-16', 'to' => '2026-05-20'],
        ], $trilha[0]->changes);
    }

    /**
     * Only what actually changed gets in.
     *
     * The amount arrives as "1000" and is stored as "1000.00": it is the same number, and
     * recording it as a change would fill the trail with noise that hides the real change.
     */
    public function test_only_what_changed_enters_the_trail(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();

        $this->editar($billing, [
            'original_amount' => '1000',
            'monthly_interest_rate' => '0.02',
            'description' => 'Outra descrição',
        ])->assertOk();

        $this->assertSame(['description'], array_keys($this->trilha($billing)[0]->changes));
    }

    public function test_an_edit_that_changes_nothing_records_nothing(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();

        $this->editar($billing, [])->assertOk();

        $this->assertCount(0, $this->trilha($billing));
    }

    // --- pagamento ----------------------------------------------------

    /**
     * The payment goes in with the frozen amounts.
     *
     * That is what the reversal will need: when the billing goes back to pending and the
     * payment columns are cleared, what was paid stays recorded here.
     */
    public function test_a_payment_enters_the_trail_with_the_frozen_amounts(): void
    {
        $this->travelTo(self::AGORA);
        $user = $this->comoUsuario();
        $billing = $this->billing();

        $this->postJson("/api/billings/{$billing->id}/payment")->assertOk();

        $trilha = $this->trilha($billing);

        $this->assertCount(1, $trilha);
        $this->assertSame('paid', $trilha[0]->event->value);
        $this->assertSame($user->id, $trilha[0]->user_id);
        $this->assertMudancas([
            'status' => ['from' => 'pending', 'to' => 'paid'],
            'payment_date' => ['from' => null, 'to' => '2026-06-15'],
            'paid_amount' => ['from' => null, 'to' => '1020.00'],
            'paid_interest_amount' => ['from' => null, 'to' => '20.00'],
        ], $trilha[0]->changes);
    }

    /** A replay with the same key does not reprocess, so it does not record again. */
    public function test_an_idempotent_replay_does_not_duplicate_the_trail(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();

        $key = ['Idempotency-Key' => '0c5e1f7a-2b8d-4e3c-9a61-7d4f2e8b1c05'];

        $this->withHeaders($key)->postJson("/api/billings/{$billing->id}/payment")->assertOk();
        $this->withHeaders($key)->postJson("/api/billings/{$billing->id}/payment")->assertOk();

        $this->assertCount(1, $this->trilha($billing));
    }

    // --- what does not get in -----------------------------------------

    public function test_a_refused_operation_does_not_enter_the_trail(): void
    {
        $this->travelTo(self::AGORA);
        $billing = $this->billing();

        $this->comoUsuario();

        // The factory records the payment through the production RegisterPayment, and
        // esse pagamento entra na trilha — legitimamente. Por isso a contagem
        // is taken after the setup, and not compared against zero.
        $paga = Billing::factory()->paid()->create();
        $before = BillingAudit::query()->count();

        // Refused by validation: a paid billing cannot be edited.
        $this->editar($paga, ['description' => 'Tentativa'])->assertUnprocessable();

        // Refused by the role.
        $this->comoUsuario('Leitor', UserRole::Viewer);
        $this->postJson("/api/billings/{$billing->id}/payment")->assertForbidden();

        $this->assertSame($before, BillingAudit::query()->count());
    }

    // --- atomicidade --------------------------------------------------

    /**
     * Without a record in the trail, the change does not happen.
     *
     * The failure is simulated on the trail model's own event, and not with DDL: renaming the
     * table mid-test would end RefreshDatabase's transaction through an implicit commit (see
     * the testing skill).
     */
    public function test_without_the_trail_the_edit_does_not_happen(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();

        BillingAudit::creating(fn () => throw new RuntimeException('Falha simulada ao gravar a trilha.'));

        $this->editar($billing, ['description' => 'Não pode ficar'])->assertServerError();

        $this->assertSame('Mensalidade de maio', $billing->fresh()->description);
    }

    public function test_without_the_trail_the_payment_does_not_happen(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();

        BillingAudit::creating(fn () => throw new RuntimeException('Falha simulada ao gravar a trilha.'));

        $this->postJson("/api/billings/{$billing->id}/payment")->assertServerError();

        $this->assertSame('pending', $billing->fresh()->status->value);
        $this->assertNull($billing->fresh()->paid_amount);
    }

    // --- imutabilidade ------------------------------------------------

    /** A wrong record in the trail is corrected with another record, never by rewriting. */
    public function test_the_trail_cannot_be_altered(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();
        $this->editar($billing, ['description' => 'Corrigida'])->assertOk();

        $this->expectException(LogicException::class);

        $this->trilha($billing)[0]->update(['changes' => []]);
    }

    public function test_the_trail_cannot_be_deleted(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();
        $this->editar($billing, ['description' => 'Corrigida'])->assertOk();

        $this->expectException(LogicException::class);

        $this->trilha($billing)[0]->delete();
    }

    /**
     * A change made outside a request — tinker, an artisan command — gets in too, with no
     * author. Leaving it out would be the easiest hole to walk through.
     */
    public function test_a_change_with_no_authenticated_user_is_recorded_without_an_author(): void
    {
        $this->travelTo(self::AGORA);
        $billing = $this->billing();

        $billing->update(['description' => 'Alterada pelo console']);

        $trilha = $this->trilha($billing);

        $this->assertCount(1, $trilha);
        $this->assertNull($trilha[0]->user_id);
    }

    // --- leitura ------------------------------------------------------

    public function test_the_api_reads_the_trail_newest_first(): void
    {
        $this->travelTo(self::AGORA);
        $this->comoUsuario();
        $billing = $this->billing();

        $this->editar($billing, ['due_date' => '2026-05-20'])->assertOk();
        $this->postJson("/api/billings/{$billing->id}/payment")->assertOk();

        $this->getJson("/api/billings/{$billing->id}/audit")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event', 'paid')
            ->assertJsonPath('data.0.event_label', 'Pagamento registrado')
            ->assertJsonPath('data.0.user.name', 'Marina Costa')
            ->assertJsonPath('data.0.created_at', now()->toIso8601String())
            ->assertJsonPath('data.0.changes.0', [
                'field' => 'status',
                'label' => 'Status',
                'from' => 'pending',
                'to' => 'paid',
            ])
            ->assertJsonPath('data.1.event', 'updated')
            ->assertJsonPath('data.1.changes.0.label', 'Vencimento');
    }

    // --- porta dos fundos ---------------------------------------------

    /**
     * The trail is written by an Eloquent event, so a raw query that changes a billing slips
     * past it without warning.
     *
     * Este teste varre `app/` atrás desse caso. Tem limite, e o limite fica
     * said: it catches the write chained in the same statement — `DB::table('billings')
     * ->update(...)`, `Billing::query()->...->update(...)` — and it does not catch a builder
     * held in a variable and updated three lines later. It exists so the obvious mistake does
     * not get past review, not to replace it.
     *
     * The seeder is outside the sweep: it lives in `database/`, it writes test volume, and two
     * million audit records would describe nothing.
     */
    public function test_no_application_code_changes_a_billing_outside_eloquent(): void
    {
        $padrao = '/(?:DB::table\(\s*[\'"]billings[\'"]\s*\)|Billing::(?:query|where\w*)\s*\()'
            .'[^;]*?->(?:update|delete|forceDelete|increment|decrement|upsert)\s*\(/s';

        $encontrados = [];

        foreach ((new Finder())->files()->in(app_path())->name('*.php') as $file) {
            if (preg_match($padrao, $file->getContents()) === 1) {
                $encontrados[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $encontrados, sprintf(
            "Alteração de cobrança por fora do Eloquent, que não passa pela trilha:\n  %s",
            implode("\n  ", $encontrados),
        ));
    }
}
