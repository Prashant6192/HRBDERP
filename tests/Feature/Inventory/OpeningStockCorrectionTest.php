<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A Super Admin books the Delhi depot's opening stock, then fixes the lines
 * that were counted or typed wrongly: change the figure, the batch, the
 * dates or the rate, or take a line out altogether. The ledger keeps the
 * first figure and every correction, each with its reason.
 */
class OpeningStockCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Facility $delhi;

    private Warehouse $delhiFg;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Prashant']);
        $this->admin->assignRole(RoleName::SuperAdmin->value);

        $this->delhi = Facility::factory()->create(['code' => 'FAC-DEL-001', 'name' => 'Delhi Warehouse']);
        $this->delhiFg = Warehouse::factory()->atFacility($this->delhi)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'DEL-FG']);

        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->product = Product::factory()->create(['name' => 'Rahat Rooh 500 ml', 'stock_uom_id' => $pcs->id, 'requires_qc' => false]);
    }

    #[Test]
    public function a_super_admin_changes_a_booked_line_and_the_history_keeps_both_figures(): void
    {
        $lot = $this->book('279', '500', '710');

        $this->actingAs($this->admin)->get(route('facilities.opening-stock.create', $this->delhi))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('facilities/opening-stock')
                ->where('can.correct', true)
                ->has('booked', 1)
                ->where('booked.0.lot_id', $lot->id)
                ->where('booked.0.quantity', '500')
                ->where('booked.0.locked', null));

        $this->actingAs($this->admin)->patch(route('facilities.opening-stock.update', [$this->delhi, $lot]), [
            'quantity' => '480',
            'batch_number' => '279A',
            'manufactured_at' => '2026-08-01',
            'expiry_at' => '2029-07-31',
            'unit_cost' => '700',
            'reason' => 'Counted again: 480, not 500',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('480', $this->onHand());
        $lot->refresh();
        $this->assertSame('279A', $lot->batch_number);
        $this->assertSame('2029-07-31', $lot->expiry_at->toDateString());
        $this->assertEquals(700, (float) $lot->unit_cost);

        $correction = InventoryTransaction::query()->where('type', InventoryTransactionType::OpeningCorrection->value)->sole();
        $this->assertStringContainsString('Counted again', $correction->reason);
        $this->assertSame($this->admin->id, $correction->created_by);
        // The first booking is untouched.
        $this->assertSame(1, InventoryTransaction::query()->where('type', InventoryTransactionType::OpeningBalance->value)->count());

        $this->actingAs($this->admin)->get(route('facilities.opening-stock.create', $this->delhi))
            ->assertInertia(fn (Assert $page) => $page
                ->where('booked.0.quantity', '480')
                ->where('booked.0.booked_quantity', '500')
                ->where('booked.0.corrected', true));
    }

    #[Test]
    public function a_super_admin_removes_a_line_booked_by_mistake(): void
    {
        $lot = $this->book('280', '120');

        $this->actingAs($this->admin)->delete(route('facilities.opening-stock.destroy', [$this->delhi, $lot]), [
            'reason' => 'Booked in the wrong store',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('0', $this->onHand());

        $this->actingAs($this->admin)->get(route('facilities.opening-stock.create', $this->delhi))
            ->assertInertia(fn (Assert $page) => $page->where('booked.0.locked', 'Removed'));

        // Once removed it cannot be removed again.
        $this->actingAs($this->admin)->delete(route('facilities.opening-stock.destroy', [$this->delhi, $lot]), [
            'reason' => 'Trying twice',
        ])->assertSessionHasErrors('correction');
    }

    #[Test]
    public function a_batch_that_has_been_used_can_no_longer_be_corrected_here(): void
    {
        $lot = $this->book('281', '100');
        app(InventoryLedgerService::class)->issue($this->product, $this->delhiFg, '10', $lot, reason: 'Sold', userId: $this->admin->id);

        $this->actingAs($this->admin)->patch(route('facilities.opening-stock.update', [$this->delhi, $lot]), [
            'quantity' => '90', 'reason' => 'Should not be allowed',
        ])->assertSessionHasErrors('correction');

        $this->actingAs($this->admin)->delete(route('facilities.opening-stock.destroy', [$this->delhi, $lot]), [
            'reason' => 'Should not be allowed',
        ])->assertSessionHasErrors('correction');

        $this->assertSame('90', $this->onHand());

        $this->actingAs($this->admin)->get(route('facilities.opening-stock.create', $this->delhi))
            ->assertInertia(fn (Assert $page) => $page->where('booked.0.locked', 'Already used or moved (Adjustment (out))'));
    }

    #[Test]
    public function a_reason_is_required_and_a_closed_facility_is_closed_for_corrections_too(): void
    {
        $lot = $this->book('282', '50');

        $this->actingAs($this->admin)->patch(route('facilities.opening-stock.update', [$this->delhi, $lot]), [
            'quantity' => '40', 'reason' => '',
        ])->assertSessionHasErrors('reason');

        $this->delhi->forceFill(['opening_stock_enabled' => false])->save();

        $this->actingAs($this->admin)->patch(route('facilities.opening-stock.update', [$this->delhi, $lot]), [
            'quantity' => '40', 'reason' => 'Counted again',
        ])->assertSessionHasErrors('correction');

        $this->assertSame('50', $this->onHand());
    }

    #[Test]
    public function only_those_who_book_opening_stock_and_may_reverse_can_correct_it(): void
    {
        $lot = $this->book('283', '60');

        $storeman = User::factory()->create();
        $storeman->assignRole(RoleName::StoreExecutive->value);

        $this->actingAs($storeman)->delete(route('facilities.opening-stock.destroy', [$this->delhi, $lot]), [
            'reason' => 'Not my job',
        ])->assertForbidden();

        // A batch from another facility cannot be reached through this one.
        $other = Facility::factory()->create(['code' => 'FAC-RDP-001']);
        $this->actingAs($this->admin)->delete(route('facilities.opening-stock.destroy', [$other, $lot]), [
            'reason' => 'Wrong facility',
        ])->assertNotFound();

        $this->assertSame('60', $this->onHand());
    }

    private function book(string $batch, string $quantity, ?string $rate = null): InventoryLot
    {
        $this->actingAs($this->admin)->post(route('facilities.opening-stock.store', $this->delhi), [
            'warehouse_id' => (string) $this->delhiFg->id,
            'as_of' => now()->toDateString(),
            'remarks' => '',
            'lines' => [[
                'item_id' => (string) $this->product->id,
                'batch_number' => $batch,
                'quantity' => $quantity,
                'uom_id' => '',
                'manufactured_at' => '',
                'expiry_at' => '',
                'unit_cost' => $rate ?? '',
                'remarks' => '',
            ]],
        ])->assertSessionHasNoErrors();

        return InventoryLot::query()->where('batch_number', $batch)->sole();
    }

    private function onHand(): string
    {
        $value = app(StockBalanceService::class)->onHand($this->product, $this->delhiFg)->__toString();

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
