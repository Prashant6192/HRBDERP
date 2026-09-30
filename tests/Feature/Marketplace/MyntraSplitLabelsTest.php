<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\LabelFile;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Services\OnlineOrderService;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeLabelReader;
use Tests\Support\LabelFixtures;
use Tests\TestCase;

/**
 * Myntra sends the day's courier labels and its tax invoices as two PDFs.
 * The label has the AWB and the buyer; the invoice has the product, the
 * order and the PacketID. Uploaded one after the other, they become one
 * parcel per order: scanned by either barcode, printed label then invoice.
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

        $this->reader = new FakeLabelReader;
        $this->reader->byFile = [
            'myntra-labels.pdf' => [
                ['pages' => [1], 'awb' => '219143269129754', 'courier' => 'Delhivery', 'payment_mode' => 'prepaid', 'customer_name' => 'Subhash Singh', 'customer_pincode' => '802217', 'customer_state' => 'Bihar', 'lines' => []],
                ['pages' => [2], 'awb' => '219143269129761', 'courier' => 'Delhivery', 'payment_mode' => 'prepaid', 'customer_name' => 'Anita Rao', 'customer_pincode' => '560001', 'lines' => []],
            ],
            'myntra-invoices.pdf' => [
                ['pages' => [1], 'awb' => null, 'alt_code' => '1000171478325', 'order_number' => '1342479-9321015-8573101', 'invoice_number' => 'I0527MG000000100',
                    'customer_name' => 'SUBHASH SINGH', 'customer_pincode' => '802217', 'payable_amount' => '324.00',
                    'lines' => [['seller_sku' => 'RTTHHROL100835002', 'description' => 'RAHAT ROOH Medicated Hair Oil - 500 ml', 'quantity' => 1]]],
            ],
            'myntra-invoices-again.pdf' => [
                ['pages' => [1], 'awb' => null, 'alt_code' => '1000171478325', 'order_number' => '1342479-9321015-8573101',
                    'customer_name' => 'Subhash Singh', 'customer_pincode' => '802217',
                    'lines' => [['seller_sku' => 'RTTHHROL100835002', 'description' => 'Medicated Hair Oil', 'quantity' => 1]]],
            ],
        ];
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
        app(OnlineOrderService::class)->mapSku($this->myntra, $this->brand, 'RTTHHROL100835002', $oil, 1, $this->agency);

        $this->shanu = User::factory()->create(['name' => 'Shanu Kumar']);
        $this->shanu->assignRole(RoleName::WarehouseManager->value);
        app(EmployeeAssignmentService::class)->assign($this->shanu, $depot, null, ['is_primary' => true], null);
    }

    private function upload(string $name, int $pages, int $variant): void
    {
        app(OnlineOrderService::class)->upload($this->brand, $this->myntra, $this->depotFg, [LabelFixtures::imageOnly($pages, $name, $variant)], $this->agency);
    }

    #[Test]
    public function the_label_and_its_invoice_become_one_parcel(): void
    {
        $this->upload('myntra-labels.pdf', 2, 1);

        $subhash = Shipment::query()->where('awb', '219143269129754')->sole();
        $this->assertSame(StockState::Unmapped, $subhash->stock_state);
        $this->assertTrue(collect($subhash->warnings)->contains(fn ($w) => str_starts_with($w, 'Waiting for the invoice file')));

        $this->upload('myntra-invoices.pdf', 1, 2);

        // Two parcels, not three: the invoice joined Subhash's label.
        $this->assertSame(2, Shipment::query()->count());
        $subhash->refresh();
        $this->assertSame('1342479-9321015-8573101', $subhash->order_number);
        $this->assertSame('1000171478325', $subhash->alt_code);
        $this->assertSame('I0527MG000000100', $subhash->invoice_number);
        $this->assertSame('RTTHHROL100835002', $subhash->lines()->sole()->seller_sku);
        $this->assertSame(StockState::Reserved, $subhash->stock_state, 'Mapped and held.');
        $this->assertSame(LabelFile::query()->where('original_name', 'myntra-invoices.pdf')->sole()->id, $subhash->invoice_file_id);
        $this->assertSame([1], $subhash->invoice_pages);
        $this->assertSame([], $subhash->warnings ?? []);

        // Anita's invoice has not come: her label still says so.
        $anita = Shipment::query()->where('awb', '219143269129761')->sole();
        $this->assertTrue(collect($anita->warnings)->contains(fn ($w) => str_starts_with($w, 'Waiting for the invoice file')));

        // The same order's invoice again adds nothing, and says why.
        try {
            $this->upload('myntra-invoices-again.pdf', 1, 3);
            $this->fail('An invoice for an order already in was taken twice.');
        } catch (OnlineOrderException $e) {
            $this->assertStringContainsString('already uploaded earlier', $e->getMessage());
        }
        $this->assertSame(2, Shipment::query()->count());
    }

    #[Test]
    public function either_barcode_scans_the_parcel_and_printing_puts_the_invoice_after_the_label(): void
    {
        $this->upload('myntra-labels.pdf', 2, 1);
        $this->upload('myntra-invoices.pdf', 1, 2);
        $subhash = Shipment::query()->where('awb', '219143269129754')->sole();

        $plan = $this->actingAs($this->shanu)->postJson(route('online-orders.print-selected'), ['shipment_ids' => [$subhash->id]])->assertOk()->json();
        $this->assertCount(2, $plan['parts']);
        $this->assertSame([$subhash->label_file_id, $subhash->invoice_file_id], array_column($plan['parts'], 'file_id'));
        $this->assertSame(2, $plan['pages']);

        // The invoice's PacketID barcode finds the same parcel.
        $ok = $this->actingAs($this->shanu)->postJson(route('floor.pack.scan'), ['code' => '1000171478325'])->assertOk()->json();
        $this->assertSame('scanned', $ok['result']);
        $this->assertSame(ShipmentStatus::Packed, $subhash->refresh()->status);

        $again = $this->actingAs($this->shanu)->postJson(route('floor.pack.scan'), ['code' => '219143269129754'])->assertOk()->json();
        $this->assertSame('already', $again['result']);
    }

    #[Test]
    public function the_invoice_first_then_the_label_pairs_the_same_way(): void
    {
        $this->upload('myntra-invoices.pdf', 1, 2);
        $this->assertSame(1, Shipment::query()->whereNull('awb')->count());

        $this->upload('myntra-labels.pdf', 2, 1);

        $this->assertSame(2, Shipment::query()->count());
        $this->assertSame(0, Shipment::query()->whereNull('awb')->count());
        $this->assertSame('1000171478325', Shipment::query()->where('awb', '219143269129754')->sole()->alt_code);
    }
}
