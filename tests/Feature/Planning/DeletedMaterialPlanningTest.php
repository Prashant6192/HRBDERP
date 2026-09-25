<?php

declare(strict_types=1);

namespace Tests\Feature\Planning;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Contract\Models\Client;
use App\Domain\Formulation\Exceptions\MissingIngredientItemException;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Services\FormulaScalingService;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Planning\Services\ProductionPlanService;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use ErrorException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Issue #9: a material deleted after the recipe was written left the recipe
 * pointing at nothing, and planning a batch from it failed with a blank
 * server error. It must say which line is orphaned instead.
 */
class DeletedMaterialPlanningTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Formula $formula;

    private RawMaterial $fragrance;

    private User $manager;

    private Client $client;

    private Uom $kg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->facility = Facility::factory()->manufacturing()
            ->withStores([WarehouseType::RawMaterial, WarehouseType::Packaging, WarehouseType::FinishedGoods, WarehouseType::Quarantine])
            ->create(['name' => 'Rudrapur Factory']);

        $this->kg = Uom::where('code', 'KG')->sole();
        $ml = Uom::where('code', 'ML')->sole();
        $pcs = Uom::where('code', 'PCS')->sole();
        $g = Uom::where('code', 'G')->sole();

        $this->manager = User::factory()->create();
        $this->manager->assignRole(RoleName::FactoryManager->value);
        $this->client = Client::factory()->create(['name' => 'Rozz Beauty', 'is_active' => true]);

        $surfactant = RawMaterial::factory()->create(['name' => 'Surfactant A', 'stock_uom_id' => $this->kg->id]);
        $this->fragrance = RawMaterial::factory()->create(['name' => 'Fragrance Rose', 'stock_uom_id' => $this->kg->id]);
        $water = RawMaterial::factory()->create(['name' => 'Purified Water', 'stock_uom_id' => $this->kg->id, 'density_g_per_ml' => '1']);
        $bottle = PackagingMaterial::factory()->create(['name' => 'Bottle 100 ml', 'stock_uom_id' => $pcs->id]);

        $product = Product::factory()->create(['name' => 'Rozz Face Wash', 'net_content' => '100', 'net_content_uom_id' => $ml->id, 'stock_uom_id' => $pcs->id]);
        $product->packagingLines()->create(['packaging_material_id' => $bottle->id, 'quantity_per_unit' => '1']);

        $formulas = app(FormulaService::class);
        $this->formula = $formulas->create(
            ['name' => 'Rozz Face Wash', 'product_id' => $product->id, 'batch_uom_id' => $g->id],
            [
                ['item_id' => $surfactant->id, 'percentage' => '15'],
                ['item_id' => $this->fragrance->id, 'percentage' => '1'],
                ['item_id' => $water->id, 'is_qs' => true],
            ],
            $this->manager->id,
        );
        $formulas->activate($this->formula->versions()->first(), $this->manager->id);
        $this->formula->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'formula_id' => $this->formula->id,
            'facility_id' => $this->facility->id,
            'quantity' => '100',
            'uom_id' => $this->kg->id,
            'planned_start_date' => now()->addDays(3)->toDateString(),
            'manufacturing_type' => 'third_party',
            'client_id' => $this->client->id,
            'client_po_ref' => 'ROZZ/PO/2026/1',
            'client_product_name' => 'Rozz Face Wash 100 ml',
            'required_delivery_at' => now()->addDays(20)->toDateString(),
            'material_source' => 'mixed',
            'client_supplied_item_ids' => [$this->fragrance->id],
            ...$overrides,
        ];
    }

    #[Test]
    public function a_third_party_batch_is_planned_and_checked_without_a_server_error(): void
    {
        $response = $this->actingAs($this->manager)->post(route('plans.store'), $this->payload());

        $response->assertRedirect()->assertSessionHasNoErrors();
        $plan = ProductionPlan::sole();
        $this->assertSame('third_party', $plan->manufacturing_type->value);
        $this->assertSame($this->client->id, $plan->client_id);
        $this->assertSame([$this->fragrance->id], $plan->clientSuppliedItemIds());

        $this->actingAs($this->manager)->get(route('plans.show', $plan))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('plans/show')->has('rawMaterials', 3));
    }

    #[Test]
    public function a_batch_is_planned_from_exactly_what_the_screen_sends(): void
    {
        // The screen's selects send ids as text.
        $response = $this->actingAs($this->manager)->post(route('plans.store'), $this->payload([
            'formula_id' => (string) $this->formula->id,
            'facility_id' => (string) $this->facility->id,
            'uom_id' => (string) $this->kg->id,
            'client_id' => (string) $this->client->id,
        ]));

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($this->facility->id, ProductionPlan::sole()->facility_id);
    }

    #[Test]
    public function a_recipe_that_names_a_deleted_material_says_which_line_instead_of_failing(): void
    {
        $this->fragrance->delete();

        $response = $this->actingAs($this->manager)->post(route('plans.store'), $this->payload());

        $response->assertRedirect();
        $response->assertSessionHasErrors('formula_id');

        $message = session('errors')->first('formula_id');
        $this->assertStringContainsString('Rozz Face Wash', $message);
        $this->assertStringContainsString('has been deleted', $message);
        $this->assertStringContainsString('recipe line 2', $message);
        $this->assertStringContainsString('Restore the material', $message);

        $this->assertSame(0, ProductionPlan::count(), 'Nothing half-made is left behind.');
    }

    #[Test]
    public function the_scaling_service_names_every_orphaned_line_at_once(): void
    {
        $this->fragrance->delete();

        try {
            app(FormulaScalingService::class)->scale($this->formula->activeVersion, '100', $this->kg);
            $this->fail('Scaling a recipe with a deleted material should not succeed.');
        } catch (MissingIngredientItemException $e) {
            $this->assertSame([2], $e->lineNumbers);
            $this->assertStringContainsString('a material that has been deleted', $e->getMessage());
        }
    }

    #[Test]
    public function a_plan_checked_before_the_material_was_deleted_still_opens(): void
    {
        $this->actingAs($this->manager)->post(route('plans.store'), $this->payload())->assertSessionHasNoErrors();
        $plan = ProductionPlan::sole();

        $this->fragrance->delete();

        $this->actingAs($this->manager)->get(route('plans.show', $plan))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rawMaterials.1.item_name', 'Material no longer on file')
                ->where('rawMaterials.1.item_code', '—'));
    }

    #[Test]
    public function a_deleted_packaging_material_leaves_the_rest_of_the_plan_standing(): void
    {
        PackagingMaterial::query()->firstOrFail()->delete();

        $response = $this->actingAs($this->manager)->post(route('plans.store'), $this->payload());

        $response->assertRedirect()->assertSessionHasNoErrors();

        $plan = ProductionPlan::sole();
        $this->assertNotEmpty($plan->warnings);
        $this->assertStringContainsString('no longer on file', implode(' ', $plan->warnings));

        // The raw materials were still worked out.
        $this->assertSame(3, $plan->lines()->count());
    }

    /**
     * The message the planning screen would show for a failed save.
     */
    private function planningError(TestResponse $response): string
    {
        $response->assertSessionHasErrors('formula_id');

        return (string) session('errors')->first('formula_id');
    }

    #[Test]
    public function the_system_administrator_is_told_what_the_fault_actually_was(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::SuperAdmin->value);

        // The kind of fault that used to come out as a bare 500: not a
        // RuntimeException, so none of the planner's own handlers name it.
        $this->mock(ProductionPlanService::class, function ($mock): void {
            $mock->shouldReceive('create')->andThrow(new ErrorException('Attempt to read property "code" on null'));
        });

        $adminMessage = $this->planningError($this->actingAs($admin)->post(route('plans.store'), $this->payload()));

        $this->assertStringContainsString('unexpected fault', $adminMessage);
        $this->assertStringContainsString('ErrorException', $adminMessage);
        $this->assertStringContainsString('Attempt to read property', $adminMessage);

        // Everybody else sees the reference and the plain advice, nothing more.
        $managerMessage = $this->planningError($this->actingAs($this->manager)->post(route('plans.store'), $this->payload()));

        $this->assertStringContainsString('unexpected fault', $managerMessage);
        $this->assertStringNotContainsString('ErrorException', $managerMessage);
    }
}
