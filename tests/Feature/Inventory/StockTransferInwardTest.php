<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Contracts\ChallanReader;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Inventory\Services\StockTransferService;
use App\Domain\Inventory\Support\ChallanCode;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\EmployeeAssignment;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\WarehouseResolver;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeChallanReader;
use Tests\TestCase;

/**
 * Rudrapur sends, Delhi scans the challan that came with the lorry, and
 * only then does the stock show at Delhi.
 */
class StockTransferInwardTest extends TestCase
{
    use RefreshDatabase;

    private Facility $rudrapur;

    private Facility $delhi;

    private Warehouse $rudrapurFg;

    private Warehouse $delhiFg;

    private Product $product;

    private User $owner;

    private User $rudrapurManager;

    private User $delhiStorekeeper;

    private StockTransferService $transfers;

    private StockBalanceService $balances;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->transfers = app(StockTransferService::class);
        $this->balances = app(StockBalanceService::class);

        $this->rudrapur = Facility::factory()->manufacturing()->create(['code' => 'FAC-RDP-001', 'name' => 'Rudrapur Manufacturing Facility', 'city' => 'Rudrapur', 'address_line_1' => 'Plot 12, SIDCUL']);
        $this->rudrapurFg = Warehouse::factory()->atFacility($this->rudrapur)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'RDP-FG']);

        $this->delhi = Facility::factory()->create(['code' => 'FAC-DEL-001', 'name' => 'Delhi Warehouse', 'city' => 'Delhi', 'can_manufacture' => false]);
        $this->delhiFg = Warehouse::factory()->atFacility($this->delhi)->ofType(WarehouseType::FinishedGoods)->create(['code' => 'DEL-FG']);

        $this->owner = User::factory()->create();
        $this->owner->assignRole(RoleName::Owner->value);

        $this->rudrapurManager = User::factory()->create();
        $this->rudrapurManager->assignRole(RoleName::WarehouseManager->value);
        EmployeeAssignment::factory()->create(['user_id' => $this->rudrapurManager->id, 'facility_id' => $this->rudrapur->id]);

        $this->delhiStorekeeper = User::factory()->create(['name' => 'Delhi Storekeeper']);
        $this->delhiStorekeeper->assignRole(RoleName::StoreExecutive->value);
        EmployeeAssignment::factory()->create(['user_id' => $this->delhiStorekeeper->id, 'facility_id' => $this->delhi->id]);

        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->product = Product::factory()->create(['name' => 'Face Wash 100 ml', 'stock_uom_id' => $pcs->id, 'requires_qc' => false]);

        $lot = InventoryLot::factory()->forItem($this->product)->create(['qc_status' => LotQcStatus::Approved, 'batch_number' => 'FG2609-0007']);
        app(InventoryLedgerService::class)->receive($this->product, $this->rudrapurFg, '500', $lot, userId: $this->owner->id);
    }

    // ---- Helpers -----------------------------------------------------------

    private function dispatched(string $quantity = '200'): StockTransfer
    {
        $transfer = $this->transfers->create(
            ['source_warehouse_id' => $this->rudrapurFg->id, 'destination_warehouse_id' => $this->delhiFg->id, 'reason' => 'Delhi launch stock'],
            [['item_id' => $this->product->id, 'quantity' => $quantity]],
            $this->rudrapurManager->id,
        );
        $transfer = $this->transfers->approve($transfer, $this->owner->id);

        return $this->transfers->dispatch($transfer, $this->rudrapurManager->id, 'UK-06-AB-1234');
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function bindReader(?array $data = null, ?string $failWith = null, bool $available = true): FakeChallanReader
    {
        $reader = new FakeChallanReader($data, $available, $failWith);
        $this->app->instance(ChallanReader::class, $reader);

        return $reader;
    }

    private function paperwork(string $name = 'LR-778.pdf'): File
    {
        $file = UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% lorry receipt\n");
        $file->mimeTypeToReport = 'application/pdf';

        return $file;
    }

    private function delhiOnHand(): string
    {
        return $this->balances->onHand($this->product, $this->delhiFg)->__toString();
    }

    private function receiveAll(StockTransfer $transfer): TestResponse
    {
        return $this->actingAs($this->delhiStorekeeper)->post(route('transfers.receive', $transfer), [
            'lines' => $transfer->lines->map(fn ($l) => ['line_id' => $l->id, 'quantity' => $l->outstanding()->__toString()])->all(),
        ]);
    }

    // ---- The challan -------------------------------------------------------

    #[Test]
    public function dispatch_gives_the_consignment_a_challan_with_an_inward_code(): void
    {
        $transfer = $this->dispatched();

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', (string) $transfer->challan_code);
        $this->assertSame('ABCD-2345', ChallanCode::format('ABCD2345'));

        // The source prints it.
        $response = $this->actingAs($this->rudrapurManager)->get(route('transfers.challan', $transfer));
        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());

        // The destination does not: the code stays on the paper that travels.
        $this->actingAs($this->delhiStorekeeper)->get(route('transfers.challan', $transfer))->assertForbidden();

        $this->actingAs($this->delhiStorekeeper)
            ->get(route('transfers.show', $transfer))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('transfers/show')
                ->where('inward.scanned', false)
                ->where('can.scan', true)
                ->where('can.receive', false)
                ->where('can.challan', false)
                ->missing('transfer.challan_code')
            );

        $this->actingAs($this->rudrapurManager)
            ->get(route('transfers.show', $transfer))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.challan', true)->where('can.scan', false));
    }

    #[Test]
    public function there_is_no_challan_before_dispatch(): void
    {
        $transfer = $this->transfers->create(
            ['source_warehouse_id' => $this->rudrapurFg->id, 'destination_warehouse_id' => $this->delhiFg->id],
            [['item_id' => $this->product->id, 'quantity' => '10']],
            $this->rudrapurManager->id,
        );

        $this->assertNull($transfer->challan_code);
        $this->actingAs($this->rudrapurManager)->get(route('transfers.challan', $transfer))->assertStatus(422);
    }

    #[Test]
    public function a_consignment_dispatched_before_inward_codes_gets_one_when_its_challan_is_printed(): void
    {
        $transfer = $this->dispatched();
        $transfer->forceFill(['challan_code' => null])->save();

        $this->actingAs($this->rudrapurManager)->get(route('transfers.challan', $transfer))->assertOk();

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', (string) $transfer->fresh()->challan_code);
    }

    // ---- The gate ----------------------------------------------------------

    #[Test]
    public function nothing_reaches_delhi_until_the_challan_is_scanned(): void
    {
        $transfer = $this->dispatched();

        $this->receiveAll($transfer)->assertRedirect();

        $transfer->refresh();
        $this->assertSame(StockTransferStatus::Dispatched, $transfer->status);
        $this->assertSame('0', $this->delhiOnHand());
        $this->assertSame('200.000000', $this->balances->onHand($this->product, app(WarehouseResolver::class)->inTransit())->__toString());
    }

    #[Test]
    public function scanning_the_qr_on_the_challan_unlocks_the_inward(): void
    {
        $transfer = $this->dispatched();

        // A phone camera opens the transfer with the code on the URL; a
        // handheld scanner types the same URL into the box.
        $qr = route('transfers.show', ['transfer' => $transfer->id, 'scan' => $transfer->challan_code]);

        $this->actingAs($this->delhiStorekeeper)
            ->get($qr)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('scanCode', $transfer->challan_code)->where('can.scan', true));

        $this->actingAs($this->delhiStorekeeper)
            ->post(route('transfers.scan', $transfer), ['code' => $qr])
            ->assertRedirect(route('transfers.show', $transfer));

        $transfer->refresh();
        $this->assertNotNull($transfer->scanned_at);
        $this->assertSame($this->delhiStorekeeper->id, $transfer->scanned_by);
        $this->assertSame('code', $transfer->transport_extraction['matched_by']);
        $this->assertSame('0', $this->delhiOnHand(), 'Verified is not yet received.');

        $this->actingAs($this->delhiStorekeeper)
            ->get(route('transfers.show', $transfer))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('inward.scanned', true)
                ->where('inward.scanned_by', 'Delhi Storekeeper')
                ->where('can.scan', false)
                ->where('can.receive', true)
                ->where('transfer.timeline.3.label', 'Challan scanned')
                ->where('transfer.timeline.3.done', true)
            );

        $this->receiveAll($transfer)->assertRedirect();

        $this->assertSame(StockTransferStatus::Received, $transfer->fresh()->status);
        $this->assertSame('200.000000', $this->delhiOnHand(), 'Now it shows at Delhi.');
        $this->assertSame('0.000000', $this->balances->onHand($this->product, app(WarehouseResolver::class)->inTransit())->__toString());
    }

    #[Test]
    public function the_code_can_be_typed_as_printed(): void
    {
        $transfer = $this->dispatched();

        $this->actingAs($this->delhiStorekeeper)
            ->post(route('transfers.scan', $transfer), ['code' => ' '.strtolower(ChallanCode::format($transfer->challan_code)).' '])
            ->assertRedirect(route('transfers.show', $transfer));

        $this->assertNotNull($transfer->fresh()->scanned_at);
    }

    #[Test]
    public function a_wrong_code_or_another_consignments_challan_is_refused(): void
    {
        $transfer = $this->dispatched('50');
        $other = $this->dispatched('60');

        $this->actingAs($this->delhiStorekeeper)
            ->from(route('transfers.show', $transfer))
            ->post(route('transfers.scan', $transfer), ['code' => 'ZZZZ-2222'])
            ->assertRedirect(route('transfers.show', $transfer))
            ->assertSessionHasErrors('code');

        $this->actingAs($this->delhiStorekeeper)
            ->post(route('transfers.scan', $transfer), ['code' => route('transfers.show', ['transfer' => $other->id, 'scan' => $other->challan_code])])
            ->assertSessionHasErrors('code');

        $this->assertStringContainsString($other->number, session('errors')->first('code'));

        $this->actingAs($this->delhiStorekeeper)
            ->post(route('transfers.scan', $transfer), ['code' => 'not a code at all'])
            ->assertSessionHasErrors('code');

        $this->assertNull($transfer->fresh()->scanned_at);
        $this->assertSame('0', $this->delhiOnHand());
    }

    #[Test]
    public function only_someone_at_the_destination_may_scan(): void
    {
        $transfer = $this->dispatched();

        $this->actingAs($this->rudrapurManager)
            ->post(route('transfers.scan', $transfer), ['code' => $transfer->challan_code])
            ->assertForbidden();

        $this->assertNull($transfer->fresh()->scanned_at);
    }

    #[Test]
    public function a_consignment_not_on_the_road_cannot_be_scanned(): void
    {
        $transfer = $this->transfers->create(
            ['source_warehouse_id' => $this->rudrapurFg->id, 'destination_warehouse_id' => $this->delhiFg->id],
            [['item_id' => $this->product->id, 'quantity' => '10']],
            $this->rudrapurManager->id,
        );
        $transfer = $this->transfers->approve($transfer, $this->owner->id);

        $this->actingAs($this->delhiStorekeeper)
            ->post(route('transfers.scan', $transfer), ['code' => 'ABCD2345'])
            ->assertSessionHasErrors('code');
    }

    // ---- The transport document --------------------------------------------

    #[Test]
    public function the_transporters_paperwork_can_be_read_to_verify_the_consignment(): void
    {
        $transfer = $this->dispatched();
        $reader = $this->bindReader([
            'transfer_number' => strtolower($transfer->number),
            'challan_code' => null,
            'transporter' => 'Safexpress',
            'lr_number' => 'LR-778',
            'vehicle' => 'DL-01-AB-1234',
            'date' => '12/09/2026',
            'lines' => [['description' => 'Face Wash 100 ml', 'quantity' => '200', 'batch' => 'FG2609-0007']],
        ]);

        $this->actingAs($this->delhiStorekeeper)
            ->post(route('transfers.scan', $transfer), ['document' => $this->paperwork()])
            ->assertRedirect(route('transfers.show', $transfer));

        $this->assertCount(1, $reader->calls);
        $this->assertSame('application/pdf', $reader->calls[0]['mime']);

        $transfer->refresh();
        $this->assertNotNull($transfer->scanned_at);
        $this->assertSame('document', $transfer->transport_extraction['matched_by']);
        $this->assertSame('2026-09-12', $transfer->transport_extraction['date']);
        $this->assertSame('Safexpress', $transfer->transporter);
        $this->assertSame('LR-778', $transfer->transport_reference);
        $this->assertSame('UK-06-AB-1234', $transfer->vehicle_ref, 'The vehicle the source recorded is not overwritten.');

        $stored = Storage::disk('local')->allFiles('stock-transfers/inward');
        $this->assertCount(1, $stored);
        $this->assertSame($stored[0], $transfer->transport_document_path);

        $this->actingAs($this->delhiStorekeeper)
            ->get(route('transfers.document', $transfer))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($this->delhiStorekeeper)
            ->get(route('transfers.show', $transfer))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('inward.document.name', 'LR-778.pdf')
                ->where('inward.transporter', 'Safexpress')
                ->where('inward.reference', 'LR-778')
                ->where('can.receive', true)
            );

        $this->receiveAll($transfer)->assertRedirect();
        $this->assertSame('200.000000', $this->delhiOnHand());
    }

    #[Test]
    public function paperwork_for_another_consignment_is_refused_and_not_kept(): void
    {
        $transfer = $this->dispatched();
        $this->bindReader(['transfer_number' => 'TRF-2601-00099', 'transporter' => 'Someone Else']);

        $this->actingAs($this->delhiStorekeeper)
            ->post(route('transfers.scan', $transfer), ['document' => $this->paperwork()])
            ->assertSessionHasErrors('code');

        $this->assertStringContainsString('TRF-2601-00099', session('errors')->first('code'));
        $this->assertNull($transfer->fresh()->scanned_at);
        $this->assertSame([], Storage::disk('local')->allFiles('stock-transfers/inward'));
    }

    #[Test]
    public function without_the_reader_the_paperwork_alone_is_not_enough(): void
    {
        $transfer = $this->dispatched();
        $this->bindReader(available: false);

        $this->actingAs($this->delhiStorekeeper)
            ->get(route('transfers.show', $transfer))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('inward.reader_available', false));

        $this->actingAs($this->delhiStorekeeper)
            ->post(route('transfers.scan', $transfer), ['document' => $this->paperwork()])
            ->assertSessionHasErrors('code');

        $this->assertStringContainsString('inward code', session('errors')->first('code'));
        $this->assertNull($transfer->fresh()->scanned_at);
        $this->assertSame([], Storage::disk('local')->allFiles('stock-transfers/inward'));

        // The code still works without any reader.
        $this->actingAs($this->delhiStorekeeper)
            ->post(route('transfers.scan', $transfer), ['code' => $transfer->challan_code, 'transport_reference' => 'LR-901', 'transporter' => 'VRL'])
            ->assertRedirect();

        $transfer->refresh();
        $this->assertNotNull($transfer->scanned_at);
        $this->assertSame('LR-901', $transfer->transport_reference);
        $this->assertSame('VRL', $transfer->transporter);
    }

    #[Test]
    public function the_paperwork_is_kept_alongside_a_scanned_code_even_when_it_cannot_be_read(): void
    {
        $transfer = $this->dispatched();
        $this->bindReader(failWith: 'The document could not be read right now.');

        $this->actingAs($this->delhiStorekeeper)
            ->post(route('transfers.scan', $transfer), ['code' => $transfer->challan_code, 'document' => $this->paperwork('photo.pdf')])
            ->assertRedirect(route('transfers.show', $transfer));

        $transfer->refresh();
        $this->assertNotNull($transfer->scanned_at);
        $this->assertSame('photo.pdf', $transfer->transport_document_name);
        $this->assertCount(1, Storage::disk('local')->allFiles('stock-transfers/inward'));
    }

    #[Test]
    public function only_a_pdf_or_a_photo_is_accepted_as_paperwork(): void
    {
        $transfer = $this->dispatched();

        $this->actingAs($this->delhiStorekeeper)
            ->post(route('transfers.scan', $transfer), ['document' => UploadedFile::fake()->create('notes.txt', 2, 'text/plain')])
            ->assertSessionHasErrors('document');

        $this->actingAs($this->delhiStorekeeper)
            ->post(route('transfers.scan', $transfer), [])
            ->assertSessionHasErrors(['code']);
    }
}
