<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Procurement\Contracts\InvoiceReader;
use App\Domain\Procurement\Enums\GoodsReceiptStatus;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeInvoiceReader;
use Tests\TestCase;

/**
 * What happens when a delivery is booked somewhere it cannot go, and how
 * a material that is not on file yet gets there from the bill.
 */
class GoodsReceiptPostingTest extends TestCase
{
    use RefreshDatabase;

    private Facility $plant;

    private Warehouse $rmStore;

    private Warehouse $fgStore;

    private RawMaterial $betaine;

    private Vendor $vendor;

    private User $owner;

    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->plant = Facility::factory()->manufacturing()->withStores([WarehouseType::RawMaterial, WarehouseType::Packaging, WarehouseType::Quarantine, WarehouseType::FinishedGoods])->create(['name' => 'Main Plant']);
        $this->rmStore = $this->plant->stores()->where('type', WarehouseType::RawMaterial->value)->firstOrFail();
        $this->fgStore = $this->plant->stores()->where('type', WarehouseType::FinishedGoods->value)->firstOrFail();

        $this->betaine = RawMaterial::factory()->create(['code' => 'RM-1001', 'name' => 'Cocamidopropyl Betaine', 'inci_name' => 'Cocamidopropyl Betaine', 'hsn_code' => '34021190', 'requires_qc' => true, 'stock_uom_id' => $this->kg()->id]);
        $this->vendor = Vendor::factory()->create(['name' => 'Cladic Chemicals', 'gstin' => '27AAACC1234A1Z5']);

        $this->owner = User::factory()->create();
        $this->owner->assignRole(RoleName::Owner->value);
        $this->storekeeper = User::factory()->create();
        $this->storekeeper->assignRole(RoleName::StoreExecutive->value);
    }

    private function kg(): Uom
    {
        return Uom::where('code', 'KG')->sole();
    }

    private function payload(Warehouse $store, array $line = [], array $overrides = []): array
    {
        return [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $store->id,
            'received_at' => now()->toDateString(),
            'invoice_ref' => '90-00092',
            'post_now' => true,
            'lines' => [[
                'item_id' => $this->betaine->id,
                'quantity' => '40',
                'uom_id' => $this->kg()->id,
                'unit_price' => '150',
                ...$line,
            ]],
            ...$overrides,
        ];
    }

    #[Test]
    public function a_raw_material_cannot_be_booked_into_a_finished_goods_store(): void
    {
        $this->actingAs($this->owner)
            ->from(route('goods-receipts.create'))
            ->post(route('goods-receipts.store'), $this->payload($this->fgStore))
            ->assertRedirect(route('goods-receipts.create'))
            ->assertSessionHasErrors('warehouse_id');

        $this->assertSame(0, GoodsReceipt::count());
        $this->assertStringContainsString('raw material store', session('errors')->first('warehouse_id'));
    }

    #[Test]
    public function a_delivery_needing_qc_at_a_facility_without_a_quarantine_is_kept_as_a_draft_and_explained(): void
    {
        $depot = Facility::factory()->create(['name' => 'City Depot']);
        $store = Warehouse::factory()->atFacility($depot)->create(['code' => 'DEP-GEN', 'type' => WarehouseType::General]);

        $this->actingAs($this->owner)
            ->post(route('goods-receipts.store'), $this->payload($store))
            ->assertRedirect();

        $receipt = GoodsReceipt::sole();
        $this->assertSame(GoodsReceiptStatus::Draft, $receipt->status, 'Kept as a draft, not posted, not a server error.');
        $this->assertSame(0, QcInspection::count());

        // Posting the draft later says the same thing.
        $this->actingAs($this->owner)->post(route('goods-receipts.post', $receipt))
            ->assertRedirect()->assertSessionHasErrors('receipt');
        $this->assertStringContainsString('no quarantine store at City Depot', session('errors')->first('receipt'));
        $this->assertSame(GoodsReceiptStatus::Draft, $receipt->fresh()->status);

        // Once the depot has a quarantine, it posts and the stock waits there for QC.
        $quarantine = Warehouse::factory()->atFacility($depot)->quarantine()->create(['code' => 'DEP-QA']);
        $this->actingAs($this->owner)->post(route('goods-receipts.post', $receipt))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(GoodsReceiptStatus::Received, $receipt->fresh()->status);
        $inspection = QcInspection::sole();
        $this->assertSame(LotQcStatus::Pending, $inspection->status);
        $this->assertSame($store->id, $inspection->destination_warehouse_id);
        $this->assertSame($quarantine->id, $inspection->lot->balances()->sole()->warehouse_id);
    }

    #[Test]
    public function the_right_store_posts_straight_through_to_qc(): void
    {
        $this->actingAs($this->owner)
            ->post(route('goods-receipts.store'), $this->payload($this->rmStore))
            ->assertRedirect()->assertSessionHasNoErrors();

        $receipt = GoodsReceipt::sole();
        $this->assertSame(GoodsReceiptStatus::Received, $receipt->status);
        $this->assertSame(1, QcInspection::count());
    }

    #[Test]
    public function a_bill_can_be_uploaded_from_the_raw_materials_page_and_an_unknown_line_added_as_a_new_material(): void
    {
        $reader = new FakeInvoiceReader([
            'vendor_name' => 'Cladic Chemicals', 'vendor_gstin' => '27AAACC1234A1Z5', 'invoice_number' => '90-00092', 'invoice_date' => '10-09-2026', 'currency' => 'INR',
            'lines' => [
                // Named by INCI on the bill: matched to what is on file.
                ['description' => 'COCAMIDOPROPYL BETAINE 30% (CAPB)', 'hsn' => '34021190', 'quantity' => '40', 'unit' => 'KGS', 'rate' => '150.00', 'amount' => '6000.00', 'batch' => 'CL-77', 'manufactured_at' => null, 'expiry_at' => null],
                // Not on file at all.
                ['description' => 'Decyl Glucoside 50%', 'hsn' => '34021190', 'quantity' => '25', 'unit' => 'KGS', 'rate' => '210.00', 'amount' => '5250.00', 'batch' => 'CL-78', 'manufactured_at' => null, 'expiry_at' => null],
            ],
        ]);
        $this->app->instance(InvoiceReader::class, $reader);

        // The Raw Materials page offers the upload.
        $this->actingAs($this->owner)->get(route('raw-materials.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('bill.upload', true)->where('bill.reader', true));

        $file = UploadedFile::fake()->createWithContent('cladic.pdf', "%PDF-1.4\n%bill\n");
        $file->mimeTypeToReport = 'application/pdf';
        $response = $this->actingAs($this->owner)->post(route('goods-receipts.intake'), ['invoice' => $file]);
        $token = $this->tokenFrom($response);

        $this->actingAs($this->owner)->get(route('goods-receipts.create', ['intake' => $token]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('intake.lines.0.item_id', $this->betaine->id)
                ->where('intake.lines.1.item_id', null)
                ->where('can.add_material', true));

        // A storekeeper may not add masters; the plant owner may.
        $this->actingAs($this->storekeeper)->postJson(route('raw-materials.quick'), ['name' => 'Decyl Glucoside 50%', 'stock_uom_id' => $this->kg()->id, 'reorder_level' => '25', 'minimum_stock' => '0'])
            ->assertForbidden();

        $added = $this->actingAs($this->owner)->postJson(route('raw-materials.quick'), [
            'name' => 'Decyl Glucoside 50%', 'hsn_code' => '34021190', 'stock_uom_id' => $this->kg()->id,
            'requires_qc' => true, 'shelf_life_days' => 730, 'reorder_level' => '25', 'minimum_stock' => '0', 'standard_cost' => '210',
        ])->assertOk()->assertJsonPath('label', 'RM-1002 — Decyl Glucoside 50%')->assertJsonPath('requires_qc', true);

        $glucoside = RawMaterial::query()->where('code', 'RM-1002')->sole();
        $this->assertSame('34021190', $glucoside->hsn_code);
        $this->assertTrue($glucoside->is_active);

        // Both lines are booked; the new material goes through QC like any other.
        $this->actingAs($this->owner)->post(route('goods-receipts.store'), [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->rmStore->id,
            'received_at' => now()->toDateString(),
            'intake_token' => $token,
            'post_now' => true,
            'lines' => [
                ['item_id' => $this->betaine->id, 'quantity' => '40', 'uom_id' => $this->kg()->id, 'unit_price' => '150', 'intake_index' => 0],
                ['item_id' => $added->json('value'), 'quantity' => '25', 'uom_id' => $this->kg()->id, 'unit_price' => '210', 'intake_index' => 1],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $receipt = GoodsReceipt::sole();
        $this->assertSame(GoodsReceiptStatus::Received, $receipt->status);
        $this->assertSame(2, QcInspection::count());
        $this->assertSame(1, QcInspection::query()->where('item_id', $glucoside->id)->count());
    }

    private function tokenFrom(TestResponse $response): string
    {
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertIsString($query['intake'] ?? null);

        return $query['intake'];
    }
}
