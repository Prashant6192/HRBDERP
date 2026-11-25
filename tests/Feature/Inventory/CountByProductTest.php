<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Enums\StockCountStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A depot's count as its register has it: one total per product, no batch
 * numbers, entered after the shelf was counted.
 */
class CountByProductTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $store;

    private Product $oil;

    private Product $shampoo;

    private InventoryLot $older;

    private InventoryLot $newer;

    private User $counter;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00', 'Asia/Kolkata'));

        $depot = Facility::factory()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Paper Market Facility', 'can_manufacture' => false, 'can_dispatch' => true]);
        $this->store = $depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();

        $pcs = Uom::where('code', 'PCS')->sole()->id;
        $this->oil = Product::factory()->create(['name' => 'Rahat Rooh Medicated Oil 500ml', 'stock_uom_id' => $pcs]);
        $this->shampoo = Product::factory()->create(['name' => 'Rahat Rooh Satreetha Shampoo 500ml', 'stock_uom_id' => $pcs]);

        $ledger = app(InventoryLedgerService::class);
        $this->older = InventoryLot::factory()->forItem($this->oil)->create(['qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYear()]);
        $this->newer = InventoryLot::factory()->forItem($this->oil)->create(['qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYears(2)]);
        $ledger->receive($this->oil, $this->store, '100', $this->older, InventoryTransactionType::StockAdjustmentIn);
        $ledger->receive($this->oil, $this->store, '50', $this->newer, InventoryTransactionType::StockAdjustmentIn);

        $this->counter = User::factory()->create();
        $this->counter->assignRole(RoleName::SuperAdmin->value);
        $this->checker = User::factory()->create();
        $this->checker->assignRole(RoleName::SuperAdmin->value);
    }

    private function onHand(Product $product): string
    {
        return (string) app(StockBalanceService::class)->available($product, $this->store)->strippedOfTrailingZeros();
    }

    #[Test]
    public function a_shortage_comes_off_the_newest_batch_and_is_posted_on_approval(): void
    {
        $counts = app(StockCountService::class);
        $count = $counts->start($this->store, $this->counter->id);

        $counts->recordProduct($count, $this->oil, '120', $this->counter->id);

        $lines = $count->lines()->get()->keyBy('lot_id');
        $this->assertSame('100', (string) (int) $lines[$this->older->id]->counted_quantity, 'The oldest batch, which parcels are held against, is kept whole.');
        $this->assertSame('20', (string) (int) $lines[$this->newer->id]->counted_quantity);

        $counts->submit($count, $this->counter->id);
        $this->assertSame('150', $this->onHand($this->oil), 'Nothing moves until the count is approved.');

        $counts->approve($count->refresh(), $this->checker->id);
        $this->assertSame('120', $this->onHand($this->oil));
    }

    #[Test]
    public function a_surplus_and_a_product_the_system_does_not_hold_are_added(): void
    {
        $counts = app(StockCountService::class);
        $count = $counts->start($this->store, $this->counter->id);

        $counts->recordProduct($count, $this->oil, '170', $this->counter->id);
        $counts->recordProduct($count, $this->shampoo, '209', $this->counter->id);

        $lines = $count->lines()->get();
        $this->assertSame('70', (string) (int) $lines->firstWhere('lot_id', $this->newer->id)->counted_quantity);
        $found = $lines->firstWhere('item_id', $this->shampoo->id);
        $this->assertNull($found->lot_id);
        $this->assertSame(['0', '209'], [(string) (int) $found->system_quantity, (string) (int) $found->counted_quantity]);

        $counts->submit($count, $this->counter->id);
        $counts->approve($count->refresh(), $this->checker->id);

        $this->assertSame(['170', '209'], [$this->onHand($this->oil), $this->onHand($this->shampoo)]);
    }

    #[Test]
    public function a_register_count_entered_later_allows_for_what_moved_since(): void
    {
        $ledger = app(InventoryLedgerService::class);
        $counts = app(StockCountService::class);

        // The shelf is counted on the 14th at 6 pm: 140 found, 10 missing.
        $countedAt = CarbonImmutable::parse('2026-11-14 18:00', 'Asia/Kolkata');

        // After the count 30 are packed, and 12 more arrive.
        $this->travelTo(CarbonImmutable::parse('2026-11-15 11:00', 'Asia/Kolkata'));
        $ledger->issue($this->oil, $this->store, '30', $this->older, InventoryTransactionType::StockAdjustmentOut);
        $ledger->receive($this->oil, $this->store, '12', $this->newer, InventoryTransactionType::StockAdjustmentIn);
        $this->assertSame('132', $this->onHand($this->oil));

        $count = $counts->start($this->store, $this->counter->id, 'Register of the 14th', $countedAt);
        $this->assertSame(150, (int) $count->lines->sum(fn ($l) => (float) $l->system_quantity), 'The system as it stood when the shelf was counted.');

        $counts->recordProduct($count, $this->oil, '140', $this->counter->id);
        $counts->submit($count, $this->counter->id);
        $counts->approve($count->refresh(), $this->checker->id);

        // Only the 10 that were missing come off today's stock.
        $this->assertSame('122', $this->onHand($this->oil));
    }

    #[Test]
    public function a_register_sheet_is_entered_on_nobodys_login_and_waits_for_approval(): void
    {
        $counts = app(StockCountService::class);
        // A pack is never what a sheet's row means.
        Product::factory()->create(['name' => 'Rahat Rooh Medicated Oil 500ml (Pack of 2)', 'stock_uom_id' => $this->oil->stock_uom_id, 'pack_of_item_id' => $this->oil->id]);
        Product::factory()->create(['name' => 'Rahat Rooh Satreetha Shampoo 1000ml', 'stock_uom_id' => $this->oil->stock_uom_id]);

        $rows = [
            ['label' => 'RR 500ml', 'any' => ['medicated'], 'size' => '500\\s*ml', 'quantity' => 140],
            ['label' => 'Satreetha 500ml', 'any' => ['satreetha'], 'none' => ['soap'], 'size' => '500\\s*ml', 'quantity' => 20],
        ];

        $count = $counts->enterSheet($this->store, $rows, now()->toImmutable(), 'Sheet');
        $this->assertSame(StockCountStatus::Submitted, $count->status);
        $this->assertNull($count->started_by);

        $counts->approve($count, $this->checker->id);
        $this->assertSame(['140', '20'], [$this->onHand($this->oil), $this->onHand($this->shampoo)]);

        // A row that names no product, or more than one, is left for the counter.
        Product::factory()->create(['name' => 'Rahat Rooh Satreetha Shampoo 500 ml New Pack', 'stock_uom_id' => $this->oil->stock_uom_id]);
        $count = $counts->enterSheet($this->store, [...$rows, ['label' => 'Lauki 200ml', 'any' => ['lauki'], 'size' => '200\\s*ml', 'quantity' => 30]], now()->toImmutable(), 'Sheet');
        $this->assertSame(StockCountStatus::Counting, $count->status);
        $this->assertStringContainsString('Lauki 200ml = 30 (no such product)', (string) $count->notes);
        $this->assertStringContainsString('Satreetha 500ml = 20 (could be ', (string) $count->notes);
        $this->assertSame('140', (string) (int) $count->lines()->where('item_id', $this->oil->id)->sum('counted_quantity'));
    }

    #[Test]
    public function the_screen_takes_totals_by_product_and_keeps_what_was_not_counted(): void
    {
        $ledger = app(InventoryLedgerService::class);
        $ledger->receive($this->shampoo, $this->store, '40', InventoryLot::factory()->forItem($this->shampoo)->create(['qc_status' => LotQcStatus::Approved]), InventoryTransactionType::StockAdjustmentIn);

        $this->actingAs($this->counter)->post(route('counts.store'), ['warehouse_id' => $this->store->id, 'counted_as_at' => '2026-11-20T09:00'])->assertSessionHasErrors('counted_as_at');
        $this->travelTo(CarbonImmutable::parse('2026-11-15 11:00', 'Asia/Kolkata'));
        $this->actingAs($this->counter)->post(route('counts.store'), ['warehouse_id' => $this->store->id, 'counted_as_at' => '2026-11-14T18:00'])->assertRedirect()->assertSessionHasNoErrors();
        $count = StockCount::query()->sole();
        $this->assertSame('2026-11-14 18:00', $count->counted_as_at->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'));

        $this->actingAs($this->counter)->post(route('counts.products', $count), ['products' => [['item_id' => $this->oil->id, 'counted' => 145]]])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->counter)->get(route('counts.show', $count))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products', fn ($rows) => collect($rows)->firstWhere('item_id', $this->oil->id)['variance'] === '-5'
                    && collect($rows)->firstWhere('item_id', $this->oil->id)['batches'] === 2
                    && collect($rows)->firstWhere('item_id', $this->shampoo->id)['counted'] === null));

        // The shampoo was not on the register: it stays as the system has it.
        $this->actingAs($this->counter)->post(route('counts.submit', $count))->assertRedirect();
        $this->assertSame('counting', $count->fresh()->status->value, 'A count with products not yet counted is not submitted.');

        $this->actingAs($this->counter)->post(route('counts.keep-rest', $count))->assertRedirect();
        $this->actingAs($this->counter)->post(route('counts.submit', $count))->assertRedirect();
        $this->actingAs($this->checker)->post(route('counts.approve', $count))->assertRedirect();

        $this->assertSame('approved', $count->fresh()->status->value);
        $this->assertSame(['145', '40'], [$this->onHand($this->oil), $this->onHand($this->shampoo)]);
    }

    #[Test]
    public function a_shortage_is_taken_from_whatever_batch_still_holds_it_today(): void
    {
        $ledger = app(InventoryLedgerService::class);
        $counts = app(StockCountService::class);

        // Counted at 6 pm on the 14th: 120 on the shelf of the 150 the system had.
        $countedAt = CarbonImmutable::parse('2026-11-14 18:00', 'Asia/Kolkata');

        // Since then parcels took 40 out of the newer batch, the one the
        // count's shortage was laid on, leaving it only 10.
        $this->travelTo(CarbonImmutable::parse('2026-11-15 11:00', 'Asia/Kolkata'));
        $ledger->issue($this->oil, $this->store, '40', $this->newer, InventoryTransactionType::StockAdjustmentOut);

        $count = $counts->start($this->store, $this->counter->id, 'Register of the 14th', $countedAt);
        $counts->recordProduct($count, $this->oil, '120', $this->counter->id);
        $counts->submit($count, $this->counter->id);
        $counts->approve($count->refresh(), $this->checker->id);

        // The 30 missing come off today's stock: 10 from the newer batch, 20 from the older.
        $this->assertSame('80', $this->onHand($this->oil));
        $this->assertSame(StockCountStatus::Approved, $count->fresh()->status);
    }
}
