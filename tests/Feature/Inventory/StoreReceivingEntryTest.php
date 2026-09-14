<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Issue #13: receiving starts in the store the material belongs to, and the
 * left menu no longer carries what has a home elsewhere.
 */
class StoreReceivingEntryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_store_page_offers_receiving_to_those_who_may_receive(): void
    {
        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $facility = Facility::factory()->manufacturing()
            ->withStores([WarehouseType::RawMaterial, WarehouseType::Packaging, WarehouseType::Quarantine])
            ->create();
        $packaging = $facility->stores()->where('type', WarehouseType::Packaging->value)->firstOrFail();

        $manager = User::factory()->create();
        $manager->assignRole(RoleName::FactoryManager->value);

        $this->actingAs($manager)->get(route('stock.index', ['warehouse' => $packaging->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected.id', $packaging->id)
                ->where('can.receive', true)
                ->where('can.view_receipts', true));

        // Someone who may look at stock but not receive sees no receive action.
        $operator = User::factory()->create();
        $operator->assignRole(RoleName::ProductionOperator->value);

        $response = $this->actingAs($operator)->get(route('stock.index', ['warehouse' => $packaging->id]));

        if ($response->status() === 200) {
            $response->assertInertia(fn (AssertableInertia $page) => $page->where('can.receive', false));
        } else {
            $response->assertForbidden();
        }
    }

    #[Test]
    public function the_left_menu_no_longer_carries_batches_counts_or_receipts(): void
    {
        $navigation = file_get_contents(resource_path('js/components/erp-navigation.ts'));

        foreach (["title: 'Batches'", "title: 'Stock Counts'", "title: 'Goods Receipts'"] as $entry) {
            $this->assertStringNotContainsString($entry, $navigation, "{$entry} still sits in the menu.");
        }

        // The screens themselves are still reachable; only the clutter went.
        foreach (['lots.index', 'counts.index', 'goods-receipts.index'] as $route) {
            $this->assertTrue(app('router')->has($route), "{$route} should still work.");
        }
    }
}
