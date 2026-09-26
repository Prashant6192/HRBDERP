<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\ItemCategory;
use App\Domain\MasterData\Models\Product;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The product screen offers the three categories the company sells under,
 * and no product loses the category it already has.
 */
class ProductCategoriesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_reference_data_sets_skincare_bodycare_and_haircare(): void
    {
        // What an older copy may hold: the demo names, an unused category
        // and one a product still carries.
        ItemCategory::query()->updateOrCreate(['code' => 'SKIN'], ['name' => 'Skin Care', 'item_type' => ItemType::FinishedGood, 'is_active' => true]);
        ItemCategory::factory()->create(['code' => 'OILS', 'name' => 'Oils', 'item_type' => ItemType::FinishedGood]);
        $used = ItemCategory::factory()->create(['code' => 'GIFT', 'name' => 'Gift sets', 'item_type' => ItemType::FinishedGood]);
        Product::factory()->create(['category_id' => $used->id]);
        $material = ItemCategory::factory()->create(['code' => 'HERB', 'name' => 'Herbs', 'item_type' => ItemType::RawMaterial]);

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ReferenceDataSeeder::class); // runs on every deploy

        $active = ItemCategory::query()->where('item_type', ItemType::FinishedGood->value)->where('is_active', true)->orderBy('name')->pluck('name')->all();

        $this->assertSame(['Bodycare', 'Gift sets', 'Haircare', 'Skincare'], $active);
        $this->assertSame(1, ItemCategory::query()->where('code', 'SKIN')->count());
        $this->assertFalse(ItemCategory::query()->where('code', 'OILS')->value('is_active'));
        // Raw material categories are left alone.
        $this->assertTrue($material->refresh()->is_active);
    }
}
