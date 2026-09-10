<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Domain\Access\Enums\RoleName;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Enums\UomDimension;
use App\Domain\Measurement\Models\Uom;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Raw materials, packaging and products share one table. These tests are
 * mostly about that decision holding up: each module must see only its own
 * rows, and must not become a way to read another module's.
 */
class ItemModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $factoryManager;

    private User $purchaseManager;

    private Uom $kilogram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(UomSeeder::class);

        $this->kilogram = Uom::where('code', 'KG')->sole();

        $this->factoryManager = User::factory()->create();
        $this->factoryManager->assignRole(RoleName::FactoryManager->value);

        // Procurement owns the material masters, so it is the purchase manager
        // who creates a new raw material, not the factory manager.
        $this->purchaseManager = User::factory()->create();
        $this->purchaseManager->assignRole(RoleName::PurchaseManager->value);
    }

    #[Test]
    public function each_module_lists_only_its_own_item_type(): void
    {
        RawMaterial::factory()->count(2)->create();
        PackagingMaterial::factory()->count(3)->create();
        Product::factory()->count(4)->create();

        $this->assertSame(2, RawMaterial::count());
        $this->assertSame(3, PackagingMaterial::count());
        $this->assertSame(4, Product::count());
        $this->assertSame(9, Item::count());
    }

    #[Test]
    public function a_new_item_is_stamped_with_its_module_type(): void
    {
        $material = RawMaterial::create([
            'code' => 'RM-STAMP',
            'name' => 'Stamped Material',
            'stock_uom_id' => $this->kilogram->id,
        ]);

        $this->assertSame(ItemType::RawMaterial, $material->refresh()->type);
    }

    #[Test]
    public function one_modules_url_cannot_be_used_to_read_another_modules_record(): void
    {
        // All three live in one table, so /products/{id} must not resolve a
        // raw material just because the id exists.
        $rawMaterial = RawMaterial::factory()->create();

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(RoleName::SuperAdmin->value);

        $this->actingAs($superAdmin)
            ->get(route('products.show', $rawMaterial->id))
            ->assertNotFound();

        $this->actingAs($superAdmin)
            ->get(route('raw-materials.show', $rawMaterial->id))
            ->assertOk();
    }

    #[Test]
    public function a_designer_can_read_packaging_but_not_raw_materials(): void
    {
        $designer = User::factory()->create();
        $designer->assignRole(RoleName::Designer->value);

        $this->actingAs($designer)
            ->get(route('packaging-materials.index'))
            ->assertOk();

        $this->actingAs($designer)
            ->get(route('raw-materials.index'))
            ->assertForbidden();
    }

    #[Test]
    public function the_factory_manager_reads_the_material_master_but_does_not_maintain_it(): void
    {
        $this->actingAs($this->factoryManager)
            ->get(route('raw-materials.index'))
            ->assertOk();

        $this->actingAs($this->factoryManager)
            ->post(route('raw-materials.store'), [
                'code' => 'RM-NOPE',
                'name' => 'Not Mine To Add',
                'stock_uom_id' => $this->kilogram->id,
                'reorder_level' => '100',
                'minimum_stock' => '40',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_raw_material_can_be_created(): void
    {
        $this->actingAs($this->purchaseManager)
            ->post(route('raw-materials.store'), [
                'code' => 'rm-2001',
                'name' => 'Sodium Laureth Sulphate',
                'stock_uom_id' => $this->kilogram->id,
                'reorder_level' => '100',
                'minimum_stock' => '40',
                'standard_cost' => '118.50',
                'gst_rate' => '18',
                'is_active' => true,
                'is_batch_tracked' => true,
                'requires_qc' => true,
            ])
            ->assertRedirect(route('raw-materials.index'));

        $material = RawMaterial::where('code', 'RM-2001')->sole();

        $this->assertSame('Sodium Laureth Sulphate', $material->name);
        $this->assertSame(ItemType::RawMaterial, $material->type);

        // Stored as NUMERIC and read back as an exact string, not a float.
        $this->assertSame('118.5000', $material->standard_cost);
    }

    #[Test]
    public function a_decimal_cost_keeps_every_digit_it_was_given(): void
    {
        $this->actingAs($this->purchaseManager)
            ->post(route('raw-materials.store'), [
                'code' => 'RM-PREC',
                'name' => 'Precise Material',
                'stock_uom_id' => $this->kilogram->id,
                'reorder_level' => '100',
                'minimum_stock' => '40',
                'standard_cost' => '1234.5678',
            ]);

        $this->assertSame(
            '1234.5678',
            RawMaterial::where('code', 'RM-PREC')->sole()->standard_cost,
        );
    }

    #[Test]
    public function a_maximum_below_the_minimum_is_rejected(): void
    {
        $this->actingAs($this->purchaseManager)
            ->post(route('raw-materials.store'), [
                'code' => 'RM-BAD',
                'name' => 'Impossible Levels',
                'stock_uom_id' => $this->kilogram->id,
                'reorder_level' => '100',
                'minimum_stock' => '100',
                'maximum_stock' => '10',
            ])
            ->assertSessionHasErrors('maximum_stock');
    }

    #[Test]
    public function a_negative_cost_is_rejected(): void
    {
        $this->actingAs($this->purchaseManager)
            ->post(route('raw-materials.store'), [
                'code' => 'RM-NEG',
                'name' => 'Negative Cost',
                'stock_uom_id' => $this->kilogram->id,
                'reorder_level' => '100',
                'minimum_stock' => '40',
                'standard_cost' => '-5',
            ])
            ->assertSessionHasErrors('standard_cost');
    }

    #[Test]
    public function a_zero_density_is_rejected(): void
    {
        // A density of zero would make the mass/volume bridge divide by zero.
        $this->actingAs($this->purchaseManager)
            ->post(route('raw-materials.store'), [
                'code' => 'RM-ZERO',
                'name' => 'Zero Density',
                'stock_uom_id' => $this->kilogram->id,
                'reorder_level' => '100',
                'minimum_stock' => '40',
                'density_g_per_ml' => '0',
            ])
            ->assertSessionHasErrors('density_g_per_ml');
    }

    #[Test]
    public function an_item_code_must_be_unique_across_every_module(): void
    {
        // One table, one code space: a product cannot take a raw material's
        // code, or the two would be indistinguishable on a document.
        RawMaterial::factory()->create(['code' => 'SHARED-01']);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(RoleName::SuperAdmin->value);

        $this->actingAs($superAdmin)
            ->post(route('products.store'), [
                'code' => 'SHARED-01',
                'name' => 'Clashing Product',
                'stock_uom_id' => $this->kilogram->id,
            ])
            ->assertSessionHasErrors('code');
    }

    #[Test]
    public function a_pack_unit_is_not_offered_as_a_stock_unit(): void
    {
        // A carton has no fixed size, so holding stock in cartons would make
        // every balance ambiguous. The seeder flags such units.
        $carton = Uom::where('code', 'CTN')->sole();

        $this->assertTrue($carton->requires_item_factor);
        $this->assertTrue($carton->needsItemFactor());
    }

    #[Test]
    public function a_material_must_carry_its_stock_thresholds(): void
    {
        // Issue #1: the reorder level and minimum stock are what the alert
        // levels and planning shortfalls are built on, so a material is not
        // complete without them.
        $this->actingAs($this->purchaseManager)
            ->post(route('raw-materials.store'), [
                'code' => 'RM-NOLEVELS',
                'name' => 'Unplanned Material',
                'stock_uom_id' => $this->kilogram->id,
            ])
            ->assertSessionHasErrors(['reorder_level', 'minimum_stock']);

        $this->actingAs($this->purchaseManager)
            ->post(route('packaging-materials.store'), [
                'code' => 'PM-NOLEVELS',
                'name' => 'Unplanned Cap',
                'stock_uom_id' => Uom::where('code', 'PCS')->sole()->id,
            ])
            ->assertSessionHasErrors(['reorder_level', 'minimum_stock']);

        $this->assertDatabaseMissing('items', ['code' => 'RM-NOLEVELS']);
    }

    #[Test]
    public function the_critical_threshold_cannot_sit_above_the_low_one(): void
    {
        $this->actingAs($this->purchaseManager)
            ->post(route('raw-materials.store'), [
                'code' => 'RM-UPSIDE',
                'name' => 'Upside Down Levels',
                'stock_uom_id' => $this->kilogram->id,
                'reorder_level' => '20',
                'minimum_stock' => '50',
            ])
            ->assertSessionHasErrors('minimum_stock');
    }

    #[Test]
    public function a_product_can_be_created_with_its_pack_details(): void
    {
        $millilitre = Uom::where('code', 'ML')->sole();
        $piece = Uom::where('code', 'PCS')->sole();

        $brandManager = User::factory()->create();
        $brandManager->assignRole(RoleName::BrandManager->value);

        $this->actingAs($brandManager)
            ->post(route('products.store'), [
                'code' => 'FG-9001',
                'name' => 'Hydra Smooth Shampoo 200ml',
                'stock_uom_id' => $piece->id,
                'net_content' => '200',
                'net_content_uom_id' => $millilitre->id,
                'mrp' => '349',
                'brand' => 'HRBD',
                'is_active' => true,
            ])
            ->assertRedirect(route('products.index'));

        $product = Product::where('code', 'FG-9001')->sole();

        $this->assertSame(ItemType::FinishedGood, $product->type);
        $this->assertSame('349.0000', $product->mrp);
    }

    #[Test]
    public function the_unit_seeder_defines_one_base_unit_per_dimension(): void
    {
        foreach (UomDimension::cases() as $dimension) {
            $base = Uom::where('dimension', $dimension->value)
                ->where('is_base', true)
                ->get();

            $this->assertCount(
                1,
                $base,
                "Dimension {$dimension->value} must have exactly one base unit.",
            );

            $this->assertSame($dimension->baseUnitCode(), $base->first()->code);
            $this->assertSame('1.000000000000', $base->first()->factor_to_base);
        }
    }
}
