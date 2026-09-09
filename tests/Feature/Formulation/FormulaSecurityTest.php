<?php

declare(strict_types=1);

namespace Tests\Feature\Formulation;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Formulation\Enums\FormulaAccessAction;
use App\Domain\Formulation\Exceptions\FormulaAccessException;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Models\FormulaAccessLog;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Formulation\Services\FormulaSecurityService;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The second factor in front of recipes.
 */
class FormulaSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $factoryManager;

    private User $viewer;

    private RawMaterial $secretMaterial;

    private Formula $formula;

    private FormulaSecurityService $security;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->factoryManager = User::factory()->create(['password' => Hash::make('password')]);
        $this->factoryManager->assignRole(RoleName::FactoryManager->value);

        $this->viewer = User::factory()->create();
        $this->viewer->assignRole(RoleName::Viewer->value);

        $this->secretMaterial = RawMaterial::factory()->create(['name' => 'Zebrafish Peptide Complex']);
        $water = RawMaterial::factory()->create(['name' => 'Purified Water']);

        $this->formula = app(FormulaService::class)->create([
            'name' => 'Test Serum',
            'batch_uom_id' => Uom::where('code', 'G')->value('id'),
        ], [
            ['item_id' => $this->secretMaterial->id, 'percentage' => '12.5'],
            ['item_id' => $water->id, 'is_qs' => true],
        ], $this->factoryManager->id);

        $this->security = app(FormulaSecurityService::class);
    }

    #[Test]
    public function the_list_shows_names_but_never_ingredients(): void
    {
        $response = $this->actingAs($this->factoryManager)->get(route('formulas.index'));

        $response->assertOk();
        $response->assertSee('Test Serum');
        $response->assertDontSee('Zebrafish');
        $response->assertDontSee('12.5');
    }

    #[Test]
    public function a_user_without_formula_view_is_refused_the_list(): void
    {
        $this->actingAs($this->viewer)->get(route('formulas.index'))->assertForbidden();
    }

    #[Test]
    public function opening_a_recipe_without_a_pin_sends_you_to_set_one(): void
    {
        $this->actingAs($this->factoryManager)
            ->get(route('formulas.show', $this->formula))
            ->assertRedirect(route('formulas.pin.edit'));
    }

    #[Test]
    public function setting_a_pin_needs_the_account_password_and_stores_only_a_hash(): void
    {
        $this->actingAs($this->factoryManager)
            ->post(route('formulas.pin.update'), ['password' => 'wrong', 'pin' => '2468', 'pin_confirmation' => '2468'])
            ->assertSessionHasErrors('password');

        $this->assertFalse($this->factoryManager->fresh()->hasFormulaPin());

        $this->actingAs($this->factoryManager)
            ->post(route('formulas.pin.update'), ['password' => 'password', 'pin' => '2468', 'pin_confirmation' => '2468'])
            ->assertRedirect(route('formulas.unlock'));

        $user = $this->factoryManager->fresh();
        $this->assertTrue($user->hasFormulaPin());
        $this->assertNotSame('2468', $user->formula_pin_hash);
        $this->assertTrue(Hash::check('2468', $user->formula_pin_hash));
        $this->assertSame(1, FormulaAccessLog::where('action', FormulaAccessAction::PinSet)->count());
    }

    #[Test]
    public function a_locked_user_is_sent_to_unlock_and_comes_back_after_verifying(): void
    {
        $this->security->setPin($this->factoryManager, '1357');

        $this->actingAs($this->factoryManager)
            ->get(route('formulas.show', $this->formula))
            ->assertRedirect(route('formulas.unlock'));

        $this->actingAs($this->factoryManager)
            ->post(route('formulas.verify'), ['secret' => '1357'])
            ->assertRedirect(route('formulas.show', $this->formula));

        $response = $this->actingAs($this->factoryManager)->get(route('formulas.show', $this->formula));

        $response->assertOk();
        $response->assertSee('Zebrafish Peptide Complex');
        $response->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        $this->assertSame(1, FormulaAccessLog::where('action', FormulaAccessAction::Unlocked)->count());
        $this->assertSame(1, FormulaAccessLog::where('action', FormulaAccessAction::Viewed)->where('formula_id', $this->formula->id)->count());
    }

    #[Test]
    public function a_wrong_pin_is_refused_and_five_wrong_pins_lock_the_user_out(): void
    {
        $this->security->setPin($this->factoryManager, '1357');

        for ($i = 1; $i <= 4; $i++) {
            $this->actingAs($this->factoryManager)
                ->from(route('formulas.unlock'))
                ->post(route('formulas.verify'), ['secret' => '0000'])
                ->assertRedirect(route('formulas.unlock'))
                ->assertSessionHasErrors('secret');
        }

        $this->assertSame(4, $this->factoryManager->fresh()->formula_pin_failed_attempts);

        $this->actingAs($this->factoryManager)
            ->from(route('formulas.unlock'))
            ->post(route('formulas.verify'), ['secret' => '0000'])
            ->assertSessionHasErrors('secret');

        $user = $this->factoryManager->fresh();
        $this->assertNotNull($user->formula_pin_locked_until);
        $this->assertTrue($user->formula_pin_locked_until->isFuture());

        // Even the right PIN is refused while locked out.
        $this->expectException(FormulaAccessException::class);
        $this->security->unlock($user, '1357');
    }

    #[Test]
    public function a_lockout_expires(): void
    {
        $this->security->setPin($this->factoryManager, '1357');

        foreach (range(1, 5) as $_) {
            try {
                $this->security->unlock($this->factoryManager, '0000');
            } catch (FormulaAccessException) {
            }
        }

        $this->travel(16)->minutes();

        $unlock = $this->security->unlock($this->factoryManager->fresh(), '1357');

        $this->assertTrue($unlock->isValid());
        $this->assertSame(1, FormulaAccessLog::where('action', FormulaAccessAction::LockedOut)->count());
    }

    #[Test]
    public function an_unlock_expires_after_the_configured_time(): void
    {
        config(['erp.formula_security.access_ttl_minutes' => 20]);
        $this->security->setPin($this->factoryManager, '1357');

        $this->actingAs($this->factoryManager)->post(route('formulas.verify'), ['secret' => '1357']);
        $this->actingAs($this->factoryManager)->get(route('formulas.show', $this->formula))->assertOk();

        $this->travel(21)->minutes();

        $this->actingAs($this->factoryManager)
            ->get(route('formulas.show', $this->formula))
            ->assertRedirect(route('formulas.unlock'));
    }

    #[Test]
    public function the_ttl_cannot_be_configured_outside_its_bounds(): void
    {
        config(['erp.formula_security.access_ttl_minutes' => 600]);
        $this->assertSame(60, $this->security->ttlMinutes());

        config(['erp.formula_security.access_ttl_minutes' => 1]);
        $this->assertSame(5, $this->security->ttlMinutes());
    }

    #[Test]
    public function locking_ends_the_unlock_early(): void
    {
        $this->security->setPin($this->factoryManager, '1357');
        $this->actingAs($this->factoryManager)->post(route('formulas.verify'), ['secret' => '1357']);

        $this->actingAs($this->factoryManager)->post(route('formulas.lock'))->assertRedirect(route('formulas.index'));

        $this->actingAs($this->factoryManager)
            ->get(route('formulas.show', $this->formula))
            ->assertRedirect(route('formulas.unlock'));

        $this->assertSame(1, FormulaAccessLog::where('action', FormulaAccessAction::Locked)->count());
    }

    #[Test]
    public function an_unlock_belongs_to_the_session_that_earned_it(): void
    {
        $this->security->setPin($this->factoryManager, '1357');
        $this->security->unlock($this->factoryManager, '1357', sessionToken: 'session-a');

        $this->assertTrue($this->security->isUnlocked($this->factoryManager, 'session-a'));
        $this->assertFalse($this->security->isUnlocked($this->factoryManager, 'session-b'));
    }

    #[Test]
    public function changing_the_pin_revokes_any_unlock(): void
    {
        $this->security->setPin($this->factoryManager, '1357');
        $this->security->unlock($this->factoryManager, '1357', sessionToken: 's');

        $this->security->setPin($this->factoryManager, '9999');

        $this->assertFalse($this->security->isUnlocked($this->factoryManager, 's'));
    }

    #[Test]
    public function the_general_audit_log_never_carries_the_recipe(): void
    {
        $this->security->setPin($this->factoryManager, '1357');
        $this->actingAs($this->factoryManager)->post(route('formulas.verify'), ['secret' => '1357']);

        $this->actingAs($this->factoryManager)->put(route('formulas.update', $this->formula), [
            'name' => 'Test Serum',
            'batch_size' => '100',
            'batch_uom_id' => Uom::where('code', 'G')->value('id'),
            'lines' => [
                ['item_id' => $this->secretMaterial->id, 'percentage' => '13.75'],
            ],
        ])->assertRedirect();

        $this->assertSame('13.750000', $this->formula->versions()->first()->ingredients()->first()->percentage);

        $this->assertGreaterThan(0, AuditLog::where('auditable_type', FormulaVersion::class)->count(), 'The version change itself is audited');

        foreach (AuditLog::all() as $entry) {
            $blob = json_encode([$entry->old_values, $entry->new_values, $entry->description, $entry->context]);

            // Never a quantity, and never the material in the context of a formula.
            $this->assertStringNotContainsString('13.75', $blob);
            $this->assertStringNotContainsString('percentage', $blob);

            if (str_starts_with((string) $entry->auditable_type, 'App\\Domain\\Formulation')) {
                $this->assertStringNotContainsString('Zebrafish', $blob);
            }
        }
    }

    #[Test]
    public function the_access_trail_is_append_only(): void
    {
        $entry = $this->security->record($this->factoryManager, FormulaAccessAction::Viewed, $this->formula);

        $this->expectException(\LogicException::class);
        $entry->update(['action' => FormulaAccessAction::Unlocked]);
    }
}
