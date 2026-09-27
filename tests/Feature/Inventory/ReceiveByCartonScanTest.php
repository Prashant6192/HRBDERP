<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\TransferCartonScan;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Inventory\Services\StockTransferService;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\EmployeeAssignment;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use App\Support\Scanning\ScanCode;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rudrapur sends 4 cartons of 42 to Paper Market. The depot scans each
 * carton off the lorry; the first verifies the consignment, each counts
 * once, and the last books the transfer into the store.
 */
class ReceiveByCartonScanTest extends TestCase
{
    use RefreshDatabase;

    private Facility $rudrapur;

    private Facility $depot;

    private Warehouse $depotFg;

    private Product $product;

    private InventoryLot $lot;

    private User $owner;

    private User $manager;

    private User $depotStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->rudrapur = Facility::factory()->manufacturing()->create(['code' => 'FAC-RDP-001', 'name' => 'Rudrapur Manufacturing Facility']);
        $rudrapurFg = Warehouse::factory()->atFacility($this->rudrapur)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'RDP-FG']);

        $this->depot = Facility::factory()->create(['code' => 'FAC-PM-001', 'name' => 'Paper Market Warehouse', 'can_manufacture' => false]);
        $this->depotFg = Warehouse::factory()->atFacility($this->depot)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'PM-FG']);

        $this->owner = User::factory()->create();
        $this->owner->assignRole(RoleName::Owner->value);
        $this->manager = User::factory()->create();
        $this->manager->assignRole(RoleName::WarehouseManager->value);

        $this->depotStore = User::factory()->create(['name' => 'Mahesh']);
        $this->depotStore->assignRole(RoleName::StoreExecutive->value);
        EmployeeAssignment::factory()->create(['user_id' => $this->depotStore->id, 'facility_id' => $this->depot->id]);

        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->product = Product::factory()->create(['name' => 'Satreetha Shampoo 500 ml', 'stock_uom_id' => $pcs->id, 'requires_qc' => false]);
        $this->lot = InventoryLot::factory()->forItem($this->product)->create([
            'qc_status' => LotQcStatus::Approved,
            'batch_number' => 'FG260927-041',
            'carton_plan' => ['boxes' => 4, 'units_per_box' => 42, 'start_box' => 1],
        ]);
        app(InventoryLedgerService::class)->receive($this->product, $rudrapurFg, '500', $this->lot, userId: $this->owner->id);

        $transfers = app(StockTransferService::class);
        $transfer = $transfers->create(
            ['source_warehouse_id' => $rudrapurFg->id, 'destination_warehouse_id' => $this->depotFg->id, 'reason' => 'Depot stock'],
            [['item_id' => $this->product->id, 'lot_id' => $this->lot->id, 'quantity' => '168']],
            $this->manager->id,
        );
        $transfers->dispatch($transfers->approve($transfer, $this->owner->id), $this->manager->id, 'UK06-AB-4471');
    }

    private function scan(string $code, ?string $units = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->depotStore)->post(route('receive.scan'), array_filter([
            'facility_id' => $this->depot->id,
            'code' => $code,
            'units' => $units,
        ]));
    }

    private function carton(int $box): string
    {
        return ScanCode::url(ScanCode::carton($this->lot->batch_number, $box, 42));
    }

    private function transfer(): StockTransfer
    {
        return StockTransfer::query()->sole();
    }

    #[Test]
    public function the_depot_scans_every_carton_and_the_last_one_books_the_transfer_in(): void
    {
        $this->actingAs($this->depotStore)->get(route('receive.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('receive/index')
                ->where('facility.id', $this->depot->id)
                ->has('transfers', 1)
                ->where('transfers.0.lines.0.outstanding', '168')
                ->where('transfers.0.lines.0.cartons_expected', 4)
                ->where('transfers.0.verified', false));

        $this->scan($this->carton(1))->assertSessionHasNoErrors();
        $this->assertTrue($this->transfer()->isVerified(), 'The first carton verifies the consignment.');
        $this->assertSame($this->depotStore->id, $this->transfer()->scanned_by);

        // The same carton twice is refused, and not counted.
        $this->scan($this->carton(1))->assertSessionHasErrors(['code' => 'Carton 1 of FG260927-041 was already scanned at '.now()->format('H:i').' by Mahesh. It is not counted twice.']);
        $this->assertSame(1, TransferCartonScan::query()->count());

        $this->scan($this->carton(2))->assertSessionHasNoErrors();
        $this->scan($this->carton(3))->assertSessionHasNoErrors();
        $this->assertSame(StockTransferStatus::Dispatched, $this->transfer()->status);
        $this->assertTrue(app(StockBalanceService::class)->onHand($this->product, $this->depotFg)->isZero(), 'Nothing lands before the lorry is booked in.');

        $this->scan($this->carton(4))->assertSessionHasNoErrors();

        $this->assertSame(StockTransferStatus::Received, $this->transfer()->status);
        $this->assertSame('168', app(StockBalanceService::class)->onHand($this->product, $this->depotFg)->strippedOfTrailingZeros()->__toString());
        $this->assertSame(4, TransferCartonScan::query()->whereNotNull('booked_at')->count());
        $this->assertSame($this->depotStore->id, $this->transfer()->received_by);
    }

    #[Test]
    public function a_carton_that_is_not_on_the_lorry_or_one_too_many_is_refused(): void
    {
        $other = InventoryLot::factory()->forItem($this->product)->create(['qc_status' => LotQcStatus::Approved, 'batch_number' => 'FG260820-009']);

        $this->scan(ScanCode::carton($other->batch_number, 3, 42))
            ->assertSessionHasErrors(['code' => 'Batch FG260820-009 is not on any lorry coming to Paper Market Warehouse. Check the carton, or open the transfer it belongs to.']);

        $this->scan('LOT:NO-SUCH-BATCH')->assertSessionHasErrors('code');
        $this->scan('MO:MO-2609-0001')->assertSessionHasErrors(['code' => 'That is not a carton or batch sticker. Scan the QR on the carton.']);

        foreach ([1, 2, 3, 4] as $box) {
            $this->scan($this->carton($box));
        }

        // A fifth carton of a lorry already booked in finds no lorry.
        $this->scan($this->carton(5))->assertSessionHasErrors('code');
        $this->assertSame(4, TransferCartonScan::query()->count());
    }

    #[Test]
    public function a_short_lorry_is_booked_in_by_hand_and_the_rest_stays_in_transit(): void
    {
        $this->scan($this->carton(1));
        $this->scan($this->carton(2));

        $this->actingAs($this->depotStore)->post(route('receive.book', $this->transfer()))->assertSessionHasNoErrors();

        $transfer = $this->transfer()->load('lines');
        $this->assertSame(StockTransferStatus::PartiallyReceived, $transfer->status);
        $this->assertSame('84', (string) $transfer->lines->first()->received()->strippedOfTrailingZeros());
        $this->assertSame('84', (string) $transfer->lines->first()->outstanding()->strippedOfTrailingZeros());

        // The late cartons still count, and complete the transfer.
        $this->scan($this->carton(3));
        $this->scan($this->carton(4));
        $this->assertSame(StockTransferStatus::Received, $this->transfer()->status);

        // Nothing waiting: booking again says so.
        $this->actingAs($this->depotStore)->post(route('receive.book', $this->transfer()))->assertSessionHasErrors('code');
    }

    #[Test]
    public function an_older_batch_sticker_counts_with_the_carton_plan_or_asks_for_the_units(): void
    {
        // The batch's own QR has no box number or count: the carton plan says 42.
        $this->scan(ScanCode::lot($this->lot->batch_number))->assertSessionHasNoErrors();
        $this->assertSame('42', (string) TransferCartonScan::query()->sole()->units()->strippedOfTrailingZeros());
        $this->assertSame(1, TransferCartonScan::query()->sole()->box_no);

        $this->lot->forceFill(['carton_plan' => null])->save();
        $this->scan(ScanCode::lot($this->lot->batch_number))->assertSessionHasErrors('code');
        $this->assertStringStartsWith('How many units', session('errors')->first('code'));

        $this->scan(ScanCode::lot($this->lot->batch_number), '42')->assertSessionHasNoErrors();
        $this->assertSame([1, 2], TransferCartonScan::query()->orderBy('box_no')->pluck('box_no')->all());
    }

    #[Test]
    public function only_someone_who_receives_transfers_and_works_there_may_scan(): void
    {
        $designer = User::factory()->create();
        $designer->assignRole(RoleName::Designer->value);
        $this->actingAs($designer)->get(route('receive.index'))->assertForbidden();
        $this->scan($this->carton(1), as: $designer)->assertForbidden();

        $rudrapurOnly = User::factory()->create();
        $rudrapurOnly->assignRole(RoleName::StoreExecutive->value);
        EmployeeAssignment::factory()->create(['user_id' => $rudrapurOnly->id, 'facility_id' => $this->rudrapur->id]);
        $this->scan($this->carton(1), as: $rudrapurOnly)->assertStatus(403);

        $this->assertSame(0, TransferCartonScan::query()->count());
    }

    #[Test]
    public function a_phone_camera_opening_a_carton_qr_lands_on_its_batch(): void
    {
        $this->actingAs($this->depotStore)->post(route('floor.lookup'), ['code' => $this->carton(2)])
            ->assertOk()
            ->assertJsonPath('kind', 'lot')
            ->assertJsonPath('title', 'FG260927-041');
    }
}
