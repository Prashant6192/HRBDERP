<?php

declare(strict_types=1);

namespace Tests\Feature\Formulation;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Formulation\Enums\FormulaStatus;
use App\Domain\Formulation\Enums\FormulaVersionStatus;
use App\Domain\Formulation\Exceptions\FormulaStateException;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Services\FormulaScalingService;
use App\Domain\Formulation\Services\FormulaSecurityService;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FormulaVersioningTest extends TestCase
{
    use RefreshDatabase;

    private FormulaService $formulas;

    private User $factoryManager;

    private User $director;

    private RawMaterial $surfactant;

    private RawMaterial $humectant;

    private RawMaterial $water;

    private int $gram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->formulas = app(FormulaService::class);

        $this->factoryManager = User::factory()->create();
        $this->factoryManager->assignRole(RoleName::FactoryManager->value);

        $this->director = User::factory()->create();
        $this->director->assignRole(RoleName::Director->value);

        $this->surfactant = RawMaterial::factory()->create(['name' => 'Test Surfactant']);
        $this->humectant = RawMaterial::factory()->create(['name' => 'Test Humectant']);
        $this->water = RawMaterial::factory()->create(['name' => 'Purified Water', 'density_g_per_ml' => '1']);

        $this->gram = Uom::where('code', 'G')->value('id');
    }

    private function createFormula(array $lines, array $attributes = []): Formula
    {
        return $this->formulas->create($attributes + [
            'name' => 'Test Wash',
            'batch_uom_id' => $this->gram,
        ], $lines, $this->factoryManager->id);
    }

    #[Test]
    public function creating_a_formula_opens_version_one_as_a_draft(): void
    {
        $formula = $this->createFormula([
            ['item_id' => $this->surfactant->id, 'percentage' => '15', 'grade' => 'ih', 'purpose' => 'Cleaning'],
            ['item_id' => $this->humectant->id, 'percentage' => '5.5'],
            ['item_id' => $this->water->id, 'is_qs' => true, 'qs_note' => 'QS to 100 g'],
        ]);

        $this->assertSame('FRM-0001', $formula->code);
        $this->assertSame(FormulaStatus::Draft, $formula->status);
        $this->assertNull($formula->active_version_id);

        $version = $formula->versions()->first();
        $this->assertSame(1, $version->version_number);
        $this->assertSame(FormulaVersionStatus::Draft, $version->status);
        $this->assertSame('20.500000', $version->total_percentage);
        $this->assertSame([1, 2, 3], $version->ingredients->pluck('line_no')->all());
        $this->assertSame('IH', $version->ingredients->first()->grade);
        $this->assertTrue($version->ingredients->last()->is_qs);
    }

    #[Test]
    public function a_recipe_cannot_exceed_one_hundred_percent(): void
    {
        $this->expectException(FormulaStateException::class);
        $this->expectExceptionMessage('more than 100%');

        $this->createFormula([
            ['item_id' => $this->surfactant->id, 'percentage' => '60'],
            ['item_id' => $this->humectant->id, 'percentage' => '45'],
        ]);
    }

    #[Test]
    public function only_one_line_may_be_the_filler(): void
    {
        $this->expectException(FormulaStateException::class);
        $this->expectExceptionMessage('Only one ingredient can be the QS filler');

        $this->createFormula([
            ['item_id' => $this->surfactant->id, 'is_qs' => true],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ]);
    }

    #[Test]
    public function a_material_appears_once(): void
    {
        $this->expectException(FormulaStateException::class);
        $this->expectExceptionMessage('appears twice');

        $this->createFormula([
            ['item_id' => $this->surfactant->id, 'percentage' => '1'],
            ['item_id' => $this->surfactant->id, 'percentage' => '2'],
        ]);
    }

    #[Test]
    public function packaging_cannot_go_into_a_recipe(): void
    {
        $cap = PackagingMaterial::factory()->create();

        $this->expectException(FormulaStateException::class);
        $this->expectExceptionMessage('is not a raw material');

        $this->createFormula([['item_id' => $cap->id, 'percentage' => '1']]);
    }

    #[Test]
    public function an_incomplete_recipe_cannot_be_activated(): void
    {
        $formula = $this->createFormula([['item_id' => $this->surfactant->id, 'percentage' => '15']]);

        $this->expectException(FormulaStateException::class);
        $this->expectExceptionMessage('adds up to 15.000%');

        $this->formulas->activate($formula->versions()->first(), $this->director->id);
    }

    #[Test]
    public function activating_makes_the_formula_active_with_exactly_one_active_version(): void
    {
        $formula = $this->createFormula([
            ['item_id' => $this->surfactant->id, 'percentage' => '15'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ]);

        $v1 = $this->formulas->activate($formula->versions()->first(), $this->director->id);

        $formula->refresh();
        $this->assertSame(FormulaStatus::Active, $formula->status);
        $this->assertSame($v1->id, $formula->active_version_id);
        $this->assertSame($this->director->id, $v1->approved_by);
        $this->assertNotNull($v1->activated_at);

        $v2 = $this->formulas->newVersion($formula, $this->factoryManager->id, changeSummary: 'More surfactant');
        $this->assertSame(2, $v2->version_number);
        $this->assertSame(FormulaVersionStatus::Draft, $v2->status);
        $this->assertCount(2, $v2->ingredients, 'A new version starts from the active recipe');

        $this->formulas->updateVersion($v2, [], [
            ['item_id' => $this->surfactant->id, 'percentage' => '18'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ], $this->factoryManager->id);

        $this->formulas->activate($v2, $this->director->id);

        $formula->refresh();
        $this->assertSame($v2->id, $formula->active_version_id);
        $this->assertSame(FormulaVersionStatus::Superseded, $v1->fresh()->status);
        $this->assertNotNull($v1->fresh()->superseded_at);
        $this->assertSame(1, $formula->versions()->where('status', 'active')->count());
        $this->assertSame('15.000000', $v1->fresh()->ingredients()->first()->percentage, 'History is untouched');
    }

    #[Test]
    public function the_database_itself_refuses_two_active_versions(): void
    {
        $formula = $this->createFormula([
            ['item_id' => $this->surfactant->id, 'percentage' => '15'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ]);

        $this->formulas->activate($formula->versions()->first(), $this->director->id);
        $v2 = $this->formulas->newVersion($formula, $this->factoryManager->id);

        $this->expectException(QueryException::class);

        DB::table('formula_versions')->where('id', $v2->id)->update(['status' => 'active']);
    }

    #[Test]
    public function only_one_draft_is_open_at_a_time_and_an_active_version_is_never_edited(): void
    {
        $formula = $this->createFormula([
            ['item_id' => $this->surfactant->id, 'percentage' => '15'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ]);
        $v1 = $this->formulas->activate($formula->versions()->first(), $this->director->id);

        $this->formulas->newVersion($formula, $this->factoryManager->id);

        try {
            $this->formulas->newVersion($formula, $this->factoryManager->id);
            $this->fail('A second draft was opened.');
        } catch (FormulaStateException $e) {
            $this->assertStringContainsString('already has a draft open', $e->getMessage());
        }

        $this->expectException(FormulaStateException::class);
        $this->expectExceptionMessage('cannot be edited');

        $this->formulas->updateVersion($v1, [], [['item_id' => $this->surfactant->id, 'percentage' => '1']], $this->factoryManager->id);
    }

    #[Test]
    public function a_draft_can_be_discarded_unless_it_is_the_only_version(): void
    {
        $formula = $this->createFormula([
            ['item_id' => $this->surfactant->id, 'percentage' => '15'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ]);
        $only = $formula->versions()->first();

        try {
            $this->formulas->discardVersion($only);
            $this->fail('The only version was discarded.');
        } catch (FormulaStateException $e) {
            $this->assertStringContainsString('only version', $e->getMessage());
        }

        $this->formulas->activate($only, $this->director->id);
        $draft = $this->formulas->newVersion($formula, $this->factoryManager->id);

        $this->formulas->discardVersion($draft);

        $this->assertSame(1, $formula->versions()->count());
        $this->assertNull($formula->fresh()->draftVersion());
    }

    #[Test]
    public function deleting_a_formula_is_a_soft_delete(): void
    {
        $formula = $this->createFormula([['item_id' => $this->surfactant->id, 'percentage' => '15']]);

        $this->formulas->delete($formula, $this->factoryManager->id);

        $this->assertSoftDeleted('formulas', ['id' => $formula->id]);
        $this->assertDatabaseHas('formula_versions', ['formula_id' => $formula->id]);
    }

    #[Test]
    public function a_recipe_scales_to_a_batch_in_the_store_unit(): void
    {
        $formula = $this->createFormula([
            ['item_id' => $this->surfactant->id, 'percentage' => '15'],
            ['item_id' => $this->humectant->id, 'percentage' => '5.5'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ]);

        $kg = Uom::where('code', 'KG')->first();
        $batch = app(FormulaScalingService::class)->scale($formula->versions()->first(), '25', $kg);

        $this->assertTrue($batch->fixedPercentage->isEqualTo('20.5'));
        $this->assertTrue($batch->qsPercentage->isEqualTo('79.5'));
        $this->assertTrue($batch->isComplete());

        [$surfactant, $humectant, $water] = $batch->lines;

        $this->assertSame('3.750000', $surfactant->quantity->__toString());
        $this->assertSame('3.750000', $surfactant->stockQuantity->__toString(), 'Stocked in KG, so the same number');
        $this->assertSame('1.375000', $humectant->quantity->__toString());
        $this->assertSame('19.875000', $water->quantity->__toString(), 'QS is whatever is left');
        $this->assertTrue($water->isQs);
    }

    #[Test]
    public function scaling_a_volume_recipe_assumes_unit_density_when_the_material_has_none(): void
    {
        $ml = Uom::where('code', 'ML')->first();
        $litre = Uom::where('code', 'L')->first();

        $formula = $this->createFormula([
            ['item_id' => $this->surfactant->id, 'percentage' => '10'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ], ['batch_uom_id' => $ml->id]);

        $batch = app(FormulaScalingService::class)->scale($formula->versions()->first(), '200', $litre);

        [$surfactant, $water] = $batch->lines;

        // 10% of 200 L = 20 L; the surfactant has no density, so 20 kg is an estimate.
        $this->assertSame('20.000000', $surfactant->quantity->__toString());
        $this->assertSame('20.000000', $surfactant->stockQuantity->__toString());
        $this->assertTrue($surfactant->assumedDensity);

        // Water has a density on record, so its conversion is exact.
        $this->assertSame('180.000000', $water->stockQuantity->__toString());
        $this->assertFalse($water->assumedDensity);
    }

    // ---- Through the screens ---------------------------------------------

    private function unlockedAs(User $user): static
    {
        app(FormulaSecurityService::class)->setPin($user, '1234');
        $this->actingAs($user)->post(route('formulas.verify'), ['secret' => '1234']);

        return $this;
    }

    #[Test]
    public function a_factory_manager_creates_a_formula_from_the_screen_and_a_director_activates_it(): void
    {
        $this->unlockedAs($this->factoryManager);

        $response = $this->actingAs($this->factoryManager)->post(route('formulas.store'), [
            'name' => 'Screen Wash',
            'batch_size' => '100',
            'batch_uom_id' => $this->gram,
            'lines' => [
                ['item_id' => $this->surfactant->id, 'percentage' => '15', 'grade' => 'IH', 'purpose' => 'Cleaning'],
                ['item_id' => $this->humectant->id, 'percentage' => ''],
                ['item_id' => $this->water->id, 'is_qs' => true, 'percentage' => ''],
            ],
        ]);

        $formula = Formula::where('name', 'Screen Wash')->firstOrFail();
        $response->assertRedirect(route('formulas.show', $formula));

        $version = $formula->versions()->first();
        $this->assertTrue($version->ingredients[1]->isAsRequired(), 'A blank percentage is "as required"');

        // The factory manager cannot activate.
        $this->actingAs($this->factoryManager)
            ->post(route('formulas.versions.activate', ['formula' => $formula, 'version' => $version]))
            ->assertForbidden();

        $this->unlockedAs($this->director);
        $this->actingAs($this->director)
            ->post(route('formulas.versions.activate', ['formula' => $formula, 'version' => $version]))
            ->assertRedirect(route('formulas.show', $formula));

        $this->assertSame(FormulaStatus::Active, $formula->fresh()->status);
    }

    #[Test]
    public function the_edit_screen_needs_an_open_draft(): void
    {
        $formula = $this->createFormula([
            ['item_id' => $this->surfactant->id, 'percentage' => '15'],
            ['item_id' => $this->water->id, 'is_qs' => true],
        ]);
        $this->formulas->activate($formula->versions()->first(), $this->director->id);

        $this->unlockedAs($this->factoryManager);

        $this->actingAs($this->factoryManager)
            ->get(route('formulas.edit', $formula))
            ->assertRedirect(route('formulas.show', $formula));

        $this->actingAs($this->factoryManager)
            ->post(route('formulas.versions.store', $formula))
            ->assertRedirect(route('formulas.edit', $formula));

        $this->actingAs($this->factoryManager)->get(route('formulas.edit', $formula))->assertOk();
    }

    #[Test]
    public function a_version_of_another_formula_cannot_be_activated_through_this_one(): void
    {
        $a = $this->createFormula([['item_id' => $this->surfactant->id, 'percentage' => '15'], ['item_id' => $this->water->id, 'is_qs' => true]]);
        $b = $this->createFormula([['item_id' => $this->humectant->id, 'percentage' => '5'], ['item_id' => $this->water->id, 'is_qs' => true]], ['name' => 'Other']);

        $this->unlockedAs($this->director);

        $this->actingAs($this->director)
            ->post(route('formulas.versions.activate', ['formula' => $a, 'version' => $b->versions()->first()]))
            ->assertNotFound();
    }
}
