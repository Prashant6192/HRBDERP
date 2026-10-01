<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\DTOs\BrowserPages;
use App\Domain\Marketplace\DTOs\LabelReading;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\LabelFile;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Services\LabelReaderService;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\Marketplace\Support\LabelFileStore;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\EmployeeAssignmentService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeLabelReader;
use Tests\Support\LabelFixtures;
use Tests\Support\MyntraPages;
use Tests\TestCase;

/**
 * Myntra sends the day's courier labels and its tax invoices as two PDFs.
 * The label has the AWB and the buyer; the invoice has the product, the
 * order and the PacketID. Uploaded one after the other, they become one
 * parcel per order: scanned by either barcode, printed label then invoice.
 *
 * Both are pictures, read by the uploader's browser (words and barcodes)
 * and parsed on the server — never by the AI reader. All names and
 * numbers here are made up.
 */
class MyntraSplitLabelsTest extends TestCase
{
    use RefreshDatabase;

    private FakeLabelReader $reader;

    private Warehouse $depotFg;

    private Brand $brand;

    private Marketplace $myntra;

    private User $agency;

    private User $shanu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('files');
        config(['erp.company.timezone' => 'Asia/Kolkata']);
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00', 'Asia/Kolkata'));

        // Myntra never goes to the AI reader: this fake only proves it.
        $this->reader = new FakeLabelReader;
        $this->app->instance(AiLabelReader::class, $this->reader);

        $depot = Facility::factory()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Paper Market Warehouse', 'can_manufacture' => false, 'can_dispatch' => true]);
        $this->depotFg = $depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        $this->seed(ReferenceDataSeeder::class);

        $this->brand = Brand::query()->where('code', 'RR')->sole();
        $this->myntra = Marketplace::query()->where('code', 'MYNTRA')->sole();

        $oil = Product::factory()->create(['code' => 'FG-MO-500', 'name' => 'Rahat Rooh Medicated Oil 500ml', 'stock_uom_id' => Uom::where('code', 'PCS')->sole()->id, 'client_id' => null]);
        $lot = InventoryLot::factory()->forItem($oil)->create(['qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYear()]);
        app(InventoryLedgerService::class)->receive($oil, $this->depotFg, '10', $lot, InventoryTransactionType::StockAdjustmentIn);

        $this->agency = User::factory()->create();
        $this->agency->assignRole(RoleName::EcommerceAgency->value);
        $this->agency->brands()->attach($this->brand);
        app(OnlineOrderService::class)->mapSku($this->myntra, $this->brand, 'RTTHHROL100835002', $oil, 1, $this->agency);

        $this->shanu = User::factory()->create(['name' => 'Shanu Kumar']);
        $this->shanu->assignRole(RoleName::WarehouseManager->value);
        app(EmployeeAssignmentService::class)->assign($this->shanu, $depot, null, ['is_primary' => true], null);
    }

    /**
     * Upload a picture-only PDF with what the browser read on its pages.
     *
     * @param  list<array{text: string, codes: list<array{format: string, text: string}>}>  $pages
     */
    private function upload(string $name, int $variant, array $pages): void
    {
        $file = LabelFixtures::imageOnly(count($pages), $name, $variant);
        $seen = [hash_file('sha256', $file->getRealPath()) => BrowserPages::fromJson(MyntraPages::json($pages))];

        app(OnlineOrderService::class)->upload($this->brand, $this->myntra, $this->depotFg, [$file], $this->agency, seen: $seen);
    }

    /**
     * @return list<array{text: string, codes: list<array{format: string, text: string}>}>
     */
    private function labels(): array
    {
        return [
            MyntraPages::label('219100000000011', 'Ravi Verma', '845401'),
            MyntraPages::label('219100000000028', 'Anita Rao', '560001'),
        ];
    }

    /**
     * @return list<array{text: string, codes: list<array{format: string, text: string}>}>
     */
    private function invoices(): array
    {
        return [MyntraPages::invoice('1000100000001', '1000001-2000002-3000003', 'Ravi Verma', '845401', [['sku' => 'RTTHHROL100835002', 'name' => 'RAHAT ROOH Medicated Hair Oil - 500 ml', 'qty' => 1]], '324.00')];
    }

    #[Test]
    public function the_label_and_its_invoice_become_one_parcel(): void
    {
        $this->upload('myntra-labels.pdf', 1, $this->labels());

        $ravi = Shipment::query()->where('awb', '219100000000011')->sole();
        $this->assertSame(StockState::Unmapped, $ravi->stock_state);
        $this->assertTrue(collect($ravi->warnings)->contains(fn ($w) => str_starts_with($w, 'Waiting for the invoice file')));

        $this->upload('myntra-invoices.pdf', 2, $this->invoices());

        // Two parcels, not three: the invoice joined Ravi's label.
        $this->assertSame(2, Shipment::query()->count());
        $ravi->refresh();
        $this->assertSame('1000001-2000002-3000003', $ravi->order_number);
        $this->assertSame('1000100000001', $ravi->alt_code);
        $this->assertSame('I0000MG000000001', $ravi->invoice_number);
        $this->assertSame('RTTHHROL100835002', $ravi->lines()->sole()->seller_sku);
        $this->assertSame(StockState::Reserved, $ravi->stock_state, 'Mapped and held.');
        $this->assertSame(LabelFile::query()->where('original_name', 'myntra-invoices.pdf')->sole()->id, $ravi->invoice_file_id);
        $this->assertSame([1], $ravi->invoice_pages);
        $this->assertSame([], $ravi->warnings ?? []);

        // Anita's invoice has not come: her label still says so.
        $anita = Shipment::query()->where('awb', '219100000000028')->sole();
        $this->assertTrue(collect($anita->warnings)->contains(fn ($w) => str_starts_with($w, 'Waiting for the invoice file')));

        // The same order's invoice again adds nothing, and says why.
        try {
            $this->upload('myntra-invoices-again.pdf', 3, [MyntraPages::invoice('1000100000001', '1000001-2000002-3000003', 'Ravi Verma', '845401', [['sku' => 'RTTHHROL100835002', 'name' => 'Medicated Hair Oil', 'qty' => 1]], '324.00', withQr: false)]);
            $this->fail('An invoice for an order already in was taken twice.');
        } catch (OnlineOrderException $e) {
            $this->assertStringContainsString('already uploaded earlier', $e->getMessage());
        }
        $this->assertSame(2, Shipment::query()->count());
    }

    #[Test]
    public function either_barcode_scans_the_parcel_and_printing_puts_the_invoice_after_the_label(): void
    {
        $this->upload('myntra-labels.pdf', 1, $this->labels());
        $this->upload('myntra-invoices.pdf', 2, $this->invoices());
        $ravi = Shipment::query()->where('awb', '219100000000011')->sole();

        $plan = $this->actingAs($this->shanu)->postJson(route('online-orders.print-selected'), ['shipment_ids' => [$ravi->id]])->assertOk()->json();
        $this->assertCount(2, $plan['parts']);
        $this->assertSame([$ravi->label_file_id, $ravi->invoice_file_id], array_column($plan['parts'], 'file_id'));
        $this->assertSame(2, $plan['pages']);

        // The invoice's PacketID barcode finds the same parcel.
        $ok = $this->actingAs($this->shanu)->postJson(route('floor.pack.scan'), ['code' => '1000100000001'])->assertOk()->json();
        $this->assertSame('scanned', $ok['result']);
        $this->assertSame(ShipmentStatus::Packed, $ravi->refresh()->status);

        $again = $this->actingAs($this->shanu)->postJson(route('floor.pack.scan'), ['code' => '219100000000011'])->assertOk()->json();
        $this->assertSame('already', $again['result']);
    }

    #[Test]
    public function the_invoice_first_then_the_label_pairs_the_same_way(): void
    {
        $this->upload('myntra-invoices.pdf', 2, $this->invoices());
        $this->assertSame(1, Shipment::query()->whereNull('awb')->count());

        $this->upload('myntra-labels.pdf', 1, $this->labels());

        $this->assertSame(2, Shipment::query()->count());
        $this->assertSame(0, Shipment::query()->whereNull('awb')->count());
        $this->assertSame('1000100000001', Shipment::query()->where('awb', '219100000000011')->sole()->alt_code);
    }

    #[Test]
    public function myntra_never_goes_to_the_ai_reader(): void
    {
        $this->upload('myntra-labels.pdf', 1, $this->labels());
        $this->upload('myntra-invoices.pdf', 2, $this->invoices());

        $this->assertSame([], $this->reader->calls);
        $this->assertSame('myntra', LabelFile::query()->where('original_name', 'myntra-labels.pdf')->sole()->read_with);
    }

    #[Test]
    public function a_letter_misread_in_the_name_still_pairs_on_the_pin_code(): void
    {
        $this->upload('myntra-labels.pdf', 1, [
            MyntraPages::label('219100000000011', 'Ravi Vermo', '845401'),
            MyntraPages::label('219100000000042', 'Sunita Das', '110045'),
        ]);
        $this->upload('myntra-invoices.pdf', 2, [
            ...$this->invoices(),
            MyntraPages::invoice('1000100000009', '1000001-2000002-3000009', 'Kavita Rao', '110045', [['sku' => 'RTTHHROL100835002', 'name' => 'Medicated Hair Oil', 'qty' => 1]], '324.00', 'I0000MG000000009'),
        ]);

        $ravi = Shipment::query()->where('awb', '219100000000011')->sole();
        $this->assertSame('1000001-2000002-3000003', $ravi->order_number, 'One letter apart, same PIN code: the same buyer.');

        // Same PIN code, a different buyer altogether: never joined.
        $this->assertNull(Shipment::query()->where('awb', '219100000000042')->sole()->order_number);
        $this->assertSame(1, Shipment::query()->whereNull('awb')->count());
    }

    #[Test]
    public function uploading_on_the_page_brings_what_the_browser_read(): void
    {
        $file = LabelFixtures::imageOnly(2, 'myntra-labels.pdf', 1);

        $this->actingAs($this->agency)->post(route('online-orders.store'), [
            'brand_id' => $this->brand->id,
            'marketplace_id' => $this->myntra->id,
            'files' => [$file],
            'seen' => [
                hash_file('sha256', $file->getRealPath()) => MyntraPages::json($this->labels()),
                // A key that is not a file's hash is ignored.
                'not-a-hash' => MyntraPages::json($this->invoices()),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['219100000000011', '219100000000028'], Shipment::query()->orderBy('awb')->pluck('awb')->all());
        $this->assertSame('Ravi Verma', Shipment::query()->where('awb', '219100000000011')->sole()->customer_name);
        $this->assertSame([], $this->reader->calls);

        $this->actingAs($this->agency)->get(route('online-orders.create'))->assertInertia(fn ($page) => $page
            ->where('marketplaces', fn ($list) => collect($list)->firstWhere('name', 'Myntra')['reads_in_browser'] === true
                && collect($list)->firstWhere('name', 'Meesho')['reads_in_browser'] === false));
    }

    #[Test]
    public function without_the_browser_reading_the_pages_are_flagged_for_typing(): void
    {
        app(OnlineOrderService::class)->upload($this->brand, $this->myntra, $this->depotFg, [LabelFixtures::imageOnly(2, 'myntra-labels.pdf', 1)], $this->agency);

        $this->assertSame([], $this->reader->calls);
        $this->assertSame(2, Shipment::query()->whereNull('awb')->count());
        $file = LabelFile::query()->sole();
        $this->assertTrue(collect($file->warnings)->contains(fn ($w) => str_contains($w, 'did not bring their reading')));
    }

    #[Test]
    public function a_storage_bucket_that_fails_does_not_stop_the_upload_or_the_print(): void
    {
        $broken = Mockery::mock(Filesystem::class);
        $broken->shouldReceive('put', 'exists', 'get', 'delete')->andThrow(new RuntimeException('The bucket is not reachable.'));
        Storage::set('files', $broken);

        $this->upload('myntra-labels.pdf', 1, $this->labels());
        $this->upload('myntra-invoices.pdf', 2, $this->invoices());

        $ravi = Shipment::query()->where('awb', '219100000000011')->sole();
        $this->assertSame('1000001-2000002-3000003', $ravi->order_number);

        // Printing reads the database copy.
        $file = LabelFile::query()->where('original_name', 'myntra-labels.pdf')->sole();
        $this->assertStringStartsWith('%PDF', (string) app(LabelFileStore::class)->get($file));
        $this->actingAs($this->shanu)->get(route('online-orders.files.show', $file))->assertOk();
    }

    #[Test]
    public function an_unexpected_error_on_upload_is_said_on_the_page_not_as_a_server_error(): void
    {
        $this->app->instance(LabelReaderService::class, new class extends LabelReaderService
        {
            public function __construct() {}

            public function read(string $contents, string $filename, Marketplace $marketplace, array $seen = []): LabelReading
            {
                throw new RuntimeException('Something broke inside.');
            }
        });

        $office = User::factory()->create();
        $office->assignRole(RoleName::SuperAdmin->value);

        $post = fn (User $as, string $name) => $this->actingAs($as)->from(route('online-orders.create'))->post(route('online-orders.store'), [
            'brand_id' => $this->brand->id,
            'marketplace_id' => $this->myntra->id,
            'files' => [LabelFixtures::imageOnly(1, $name, 7)],
        ]);

        $post($office, 'a.pdf')->assertRedirect(route('online-orders.create'))
            ->assertInvalid(['files' => 'The upload stopped on an error'])
            ->assertInvalid(['files' => 'RuntimeException: Something broke inside.']);
    }

    #[Test]
    public function the_agency_is_told_to_try_again_not_shown_the_inside_of_the_erp(): void
    {
        $this->app->instance(LabelReaderService::class, new class extends LabelReaderService
        {
            public function __construct() {}

            public function read(string $contents, string $filename, Marketplace $marketplace, array $seen = []): LabelReading
            {
                throw new RuntimeException('Something broke inside.');
            }
        });

        $this->actingAs($this->agency)->from(route('online-orders.create'))->post(route('online-orders.store'), [
            'brand_id' => $this->brand->id,
            'marketplace_id' => $this->myntra->id,
            'files' => [LabelFixtures::imageOnly(1, 'b.pdf', 8)],
        ])->assertRedirect(route('online-orders.create'))->assertInvalid(['files' => 'tell the office the time']);

        $this->assertStringNotContainsString('Something broke', (string) session('errors')->first('files'));
    }

    private function uploadAtSecondDepot(): Facility
    {
        $other = Facility::factory()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Second Depot', 'can_manufacture' => false, 'can_dispatch' => true]);
        $otherFg = $other->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        $file = LabelFixtures::imageOnly(2, 'myntra-labels.pdf', 1);
        app(OnlineOrderService::class)->upload($this->brand, $this->myntra, $otherFg, [$file], $this->agency, seen: [
            hash_file('sha256', $file->getRealPath()) => BrowserPages::fromJson(MyntraPages::json($this->labels())),
        ]);

        return $other;
    }

    #[Test]
    public function labels_filed_at_a_facility_the_manager_does_not_work_at_are_named_on_his_screen(): void
    {
        $this->uploadAtSecondDepot();

        $this->actingAs($this->shanu)->get(route('online-orders.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('batches', [])
            ->where('elsewhere.mine', ['Paper Market Warehouse'])
            ->where('elsewhere.places', [['facility' => 'Second Depot', 'parcels' => 2]]));
    }

    #[Test]
    public function once_he_works_there_too_he_sees_them_and_the_note_is_gone(): void
    {
        app(EmployeeAssignmentService::class)->assign($this->shanu, $this->uploadAtSecondDepot(), null, [], null);

        $this->actingAs($this->shanu)->get(route('online-orders.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->has('batches', 1)
            ->where('elsewhere', null));
    }
}
