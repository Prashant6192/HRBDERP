<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Intelligence\DTOs\FactoryException;
use App\Domain\Intelligence\Services\ExceptionService;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\HandoverSheet;
use App\Domain\Marketplace\Models\LabelBatch;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\EmployeeAssignmentService;
use App\Models\User;
use App\Notifications\LabelsReadyNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeLabelReader;
use Tests\Support\LabelFixtures;
use Tests\TestCase;

/**
 * The screens and who may use them: the agency uploads for its own brand
 * and sees nothing else; the depot prints; the packing table packs by
 * scan; the courier signs a sheet; and a label left unpacked after the
 * cut-off is raised until someone deals with it.
 */
class OnlineOrderScreensTest extends TestCase
{
    use RefreshDatabase;

    private Facility $depot;

    private Warehouse $depotFg;

    private Brand $rahatRooh;

    private Brand $cleanse;

    private Marketplace $meesho;

    private Product $oil;

    private User $agency;

    private User $accountant;

    private User $packer;

    private User $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        $this->app->instance(AiLabelReader::class, new FakeLabelReader(available: false));
        config(['erp.online_orders.cutoff' => '16:00', 'erp.company.timezone' => 'Asia/Kolkata']);

        $this->depot = Facility::factory()
            ->withStores([WarehouseType::FinishedGoods])
            ->create(['name' => 'Paper Market Depot', 'gstin' => '07AAAAA0000A1Z5', 'can_dispatch' => true]);
        $this->depotFg = $this->depot->stores()->where('type', WarehouseType::FinishedGoods->value)->firstOrFail();
        $this->seed(ReferenceDataSeeder::class);

        $this->rahatRooh = Brand::query()->where('code', 'RR')->sole();
        $this->cleanse = Brand::query()->where('code', 'CA')->sole();
        $this->meesho = Marketplace::query()->where('code', 'MEESHO')->sole();

        $this->oil = Product::factory()->create(['name' => 'Rahat Rooh Hair Oil 200 ml', 'stock_uom_id' => Uom::where('code', 'PCS')->sole()->id]);
        $lot = InventoryLot::factory()->forItem($this->oil)->create(['qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYear()]);
        app(InventoryLedgerService::class)->receive($this->oil, $this->depotFg, '50', $lot, InventoryTransactionType::StockAdjustmentIn);

        $this->agency = User::factory()->create(['name' => 'Agency Desk']);
        $this->agency->assignRole(RoleName::EcommerceAgency->value);
        $this->agency->brands()->attach($this->rahatRooh);

        $this->accountant = User::factory()->create(['name' => 'Depot Accounts']);
        $this->accountant->assignRole(RoleName::AccountsManager->value);

        $this->packer = User::factory()->create(['name' => 'Packing Table']);
        $this->packer->assignRole(RoleName::StoreExecutive->value);
        app(EmployeeAssignmentService::class)->assign($this->packer, $this->depot, null, ['is_primary' => true], null);

        $this->dispatcher = User::factory()->create(['name' => 'Dispatch Desk']);
        $this->dispatcher->assignRole(RoleName::DispatchManager->value);
    }

    /**
     * @return array<string, mixed>
     */
    private function label(string $awb, array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return [
            'awb' => $awb, 'courier' => 'Valmo', 'payment' => 'cod', 'sku' => 'Hair oil 200 ml', 'qty' => 1,
            'order' => sprintf('2000000000000%05d', $n), 'invoice' => sprintf('fghij%04d', $n), 'total' => '199.00',
            'name' => "Test Customer {$n}", 'state' => 'Bihar', ...$overrides,
        ];
    }

    private function upload(User $as, Brand $brand, array $labels, string $name = 'labels.pdf'): LabelBatch
    {
        $this->actingAs($as)->post(route('online-orders.store'), [
            'brand_id' => $brand->id,
            'marketplace_id' => $this->meesho->id,
            'files' => [LabelFixtures::meesho($labels, $name)],
        ])->assertRedirect();

        return LabelBatch::query()->latest('id')->firstOrFail();
    }

    private function mapOil(): void
    {
        MarketplaceListing::query()->delete();
        $this->actingAs($this->dispatcher)->post(route('listings.store'), [
            'marketplace_id' => $this->meesho->id,
            'brand_id' => $this->rahatRooh->id,
            'seller_sku' => 'Hair oil 200 ml',
            'item_id' => $this->oil->id,
            'units_per_order' => 1,
        ])->assertRedirect();
    }

    #[Test]
    public function the_agency_role_can_only_upload_and_follow_its_parcels(): void
    {
        $this->assertEqualsCanonicalizing(
            ['marketplace.view', 'marketplace.upload'],
            $this->agency->getAllPermissions()->pluck('name')->all(),
        );

        // Any other page takes the agency back to its online orders.
        foreach (['dashboard', 'products.index', 'dispatches.index', 'listings.index', 'brands.index', 'floor.index', 'floor.scan', 'command-centre', 'notifications.index'] as $name) {
            $response = $this->actingAs($this->agency)->get(route($name));

            $name === 'notifications.index'
                ? $response->assertOk()
                : $response->assertRedirect(route('online-orders.index'));
        }

        // Its own account settings stay open to it.
        $this->actingAs($this->agency)->get(route('profile.edit'))->assertOk();

        // Anything else it tries to do is refused outright.
        $this->actingAs($this->agency)->postJson(route('floor.lookup'), ['code' => 'VL1000000000001'])->assertForbidden();
    }

    #[Test]
    public function the_agency_uploads_for_its_own_brand_only(): void
    {
        $this->actingAs($this->agency)->get(route('online-orders.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('online-orders/upload')
                ->has('brands', 1)
                ->where('brands.0.name', 'Rahat Rooh')
                ->where('can_choose_store', false));

        $this->actingAs($this->agency)->post(route('online-orders.store'), [
            'brand_id' => $this->cleanse->id,
            'marketplace_id' => $this->meesho->id,
            'files' => [LabelFixtures::meesho([$this->label('VL1000000000001')])],
        ])->assertForbidden();

        $batch = $this->upload($this->agency, $this->rahatRooh, [$this->label('VL1000000000001'), $this->label('VL1000000000002')]);

        $this->assertSame($this->depotFg->id, $batch->warehouse_id);
        $this->assertSame(2, $batch->shipments()->count());

        // A batch of another brand is not the agency's to open.
        $other = app(OnlineOrderService::class)
            ->upload($this->cleanse, $this->meesho, $this->depotFg, [LabelFixtures::meesho([$this->label('VL1000000000009')], 'ca.pdf')], $this->dispatcher);

        $this->actingAs($this->agency)->get(route('online-orders.show', $other))->assertForbidden();
        $this->actingAs($this->agency)->get(route('online-orders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('batches', 1)
                ->where('batches.0.brand', 'Rahat Rooh')
                ->where('can.restricted', true));

        // The agency sees its parcels, but no stock figures and no print runs.
        $this->actingAs($this->agency)->get(route('online-orders.show', $batch))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('online-orders/show')
                ->has('shipments', 2)
                ->where('shortfall', [])
                ->where('prints', [])
                ->where('unmapped', [])
                ->where('shipments.0.stock_label', null)
                ->where('shipments.0.picks', [])
                ->where('shipments.0.lines.0.item', null)
                ->where('can.correct', false));

        // …and cannot print them.
        $this->actingAs($this->agency)->postJson(route('online-orders.print', $batch), ['scope' => 'all'])->assertForbidden();
    }

    #[Test]
    public function the_agency_cannot_take_back_cancel_or_change_what_it_uploaded(): void
    {
        $batch = $this->upload($this->agency, $this->rahatRooh, [$this->label('VL1000000000011')]);
        $file = $batch->files()->sole();
        $parcel = $batch->shipments()->sole();

        $this->actingAs($this->agency)->delete(route('online-orders.files.destroy', $file))->assertForbidden();
        $this->actingAs($this->agency)->post(route('online-orders.parcels.cancel', $parcel), ['reason' => 'Customer cancelled'])->assertForbidden();
        $this->actingAs($this->agency)->patch(route('online-orders.parcels.update', $parcel), ['awb' => 'VL9999999999999'])->assertForbidden();
        $this->actingAs($this->agency)->post(route('online-orders.hold', $batch))->assertForbidden();

        $this->assertDatabaseHas('label_files', ['id' => $file->id]);
        $this->assertSame(ShipmentStatus::Uploaded, $parcel->refresh()->status);
        $this->assertSame('VL1000000000011', $parcel->awb);

        // It can still close the day and open its own label file.
        $this->actingAs($this->agency)->get(route('online-orders.files.show', $file))->assertOk();
        $this->actingAs($this->agency)->post(route('online-orders.close', $batch))->assertRedirect();

        // The office can remove an upload that has not been printed.
        $other = $this->upload($this->agency, $this->rahatRooh, [$this->label('VL1000000000012')]);
        $this->actingAs($this->dispatcher)->delete(route('online-orders.files.destroy', $other->files()->sole()))->assertRedirect();
        $this->assertSame(0, $other->shipments()->count());
    }

    #[Test]
    public function closing_the_day_tells_the_depot_by_bell_and_email(): void
    {
        Notification::fake();
        $batch = $this->upload($this->agency, $this->rahatRooh, [$this->label('VL1000000000001')]);

        $this->actingAs($this->agency)->post(route('online-orders.close', $batch))->assertRedirect();

        $this->assertSame('closed', $batch->refresh()->status->value);
        Notification::assertSentTo($this->accountant, LabelsReadyNotification::class, function (LabelsReadyNotification $n, array $channels) {
            return $channels === ['database', 'mail'] && $n->parcels === 1;
        });
        Notification::assertNotSentTo($this->agency, LabelsReadyNotification::class);
    }

    #[Test]
    public function the_accountant_prints_by_courier_from_the_original_files(): void
    {
        $batch = $this->upload($this->agency, $this->rahatRooh, [
            $this->label('VL1000000000001'),
            $this->label('SF1000000001FPL', ['courier' => 'Shadowfax']),
        ]);

        $plan = $this->actingAs($this->accountant)
            ->postJson(route('online-orders.print', $batch), ['scope' => 'courier', 'courier' => 'Shadowfax'])
            ->assertOk()
            ->json();

        $this->assertSame(1, $plan['shipments']);
        $this->assertSame([2], $plan['parts'][0]['pages']);
        $fileUrl = $plan['files'][(string) $plan['parts'][0]['file_id']];

        $response = $this->actingAs($this->accountant)->get($fileUrl)->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->streamedContent());

        $this->assertSame(ShipmentStatus::Printed, Shipment::query()->where('awb', 'SF1000000001FPL')->sole()->status);
        $this->assertSame(ShipmentStatus::Uploaded, Shipment::query()->where('awb', 'VL1000000000001')->sole()->status);

        // The packing table does not print.
        $this->actingAs($this->packer)->postJson(route('online-orders.print', $batch), ['scope' => 'all'])->assertForbidden();
        $this->actingAs($this->packer)->get($fileUrl)->assertForbidden();
    }

    #[Test]
    public function the_packing_table_packs_by_scanning_and_is_stopped_with_the_reason(): void
    {
        $batch = $this->upload($this->agency, $this->rahatRooh, [
            $this->label('VL1000000000001', ['qty' => 2]),
            $this->label('VL1000000000002'),
            $this->label('VL1000000000003', ['sku' => 'Something unmapped']),
        ]);
        $this->mapOil();

        $this->actingAs($this->packer)->get(route('floor.pack'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('floor/pack')->where('waiting', 3));

        $ok = $this->actingAs($this->packer)->postJson(route('floor.pack.scan'), ['code' => 'vl1000000000001'])->assertOk()->json();
        $this->assertTrue($ok['ok']);
        $this->assertSame('Rahat Rooh Hair Oil 200 ml', $ok['shipment']['lines'][0]['item']);
        $this->assertSame('2', $ok['shipment']['lines'][0]['units']);

        $again = $this->actingAs($this->packer)->postJson(route('floor.pack.scan'), ['code' => 'VL1000000000001'])->assertStatus(422)->json();
        $this->assertStringContainsString('Already packed by Packing Table', $again['message']);

        $unmapped = $this->actingAs($this->packer)->postJson(route('floor.pack.scan'), ['code' => 'VL1000000000003'])->assertStatus(422)->json();
        $this->assertStringContainsString('does not know which product', $unmapped['message']);

        $this->actingAs($this->packer)->postJson(route('floor.pack.scan'), ['code' => 'NOPE123'])->assertNotFound();

        // The office cancels an order the customer cancelled; the packer is told not to pack it.
        $this->actingAs($this->dispatcher)->post(route('online-orders.parcels.cancel', Shipment::query()->where('awb', 'VL1000000000002')->sole()), ['reason' => 'Customer cancelled'])->assertRedirect();
        $stop = $this->actingAs($this->packer)->postJson(route('floor.pack.scan'), ['code' => 'VL1000000000002'])->assertStatus(422)->json();
        $this->assertStringContainsString('Do not pack this parcel', $stop['message']);

        // Someone assigned to another facility cannot pack the depot's parcels.
        $elsewhere = User::factory()->create();
        $elsewhere->assignRole(RoleName::StoreExecutive->value);
        $factory = Facility::factory()->withStores([WarehouseType::FinishedGoods])->create(['can_dispatch' => true]);
        app(EmployeeAssignmentService::class)->assign($elsewhere, $factory, null, ['is_primary' => true], null);
        $this->mapOil();
        $batch2 = $this->upload($this->agency, $this->rahatRooh, [$this->label('VL1000000000004')], 'b2.pdf');
        $this->actingAs($elsewhere)->postJson(route('floor.pack.scan'), ['code' => 'VL1000000000004'])->assertForbidden();
        $this->assertNotNull($batch2);
    }

    #[Test]
    public function packed_parcels_are_handed_to_the_courier_on_a_signed_sheet(): void
    {
        $this->upload($this->agency, $this->rahatRooh, [$this->label('VL1000000000001'), $this->label('VL1000000000002')]);
        $this->mapOil();

        foreach (['VL1000000000001', 'VL1000000000002'] as $awb) {
            $this->actingAs($this->packer)->postJson(route('floor.pack.scan'), ['code' => $awb])->assertOk();
        }

        $this->actingAs($this->packer)->get(route('floor.handover'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('floor/handover')->has('parcels', 2));

        $this->actingAs($this->packer)->post(route('floor.handover.store'), [
            'warehouse_id' => $this->depotFg->id,
            'courier' => 'Valmo',
            'shipment_ids' => Shipment::query()->pluck('id')->all(),
            'received_by' => 'Suresh, Valmo',
        ])->assertRedirect();

        $sheet = HandoverSheet::query()->sole();
        $this->assertSame(2, $sheet->shipment_count);
        $this->assertSame(2, Shipment::query()->where('status', ShipmentStatus::HandedOver->value)->count());

        $pdf = $this->actingAs($this->packer)->get(route('handover-sheets.pdf', $sheet))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->actingAs($this->agency)->get(route('handover-sheets.pdf', $sheet))->assertRedirect(route('online-orders.index'));
    }

    #[Test]
    public function packing_without_a_scan_is_the_offices_call_and_says_why(): void
    {
        $this->upload($this->agency, $this->rahatRooh, [$this->label('VL1000000000001')]);
        $this->mapOil();
        $parcel = Shipment::query()->sole();

        $this->actingAs($this->packer)->post(route('online-orders.parcels.pack', $parcel), ['reason' => 'Label torn'])->assertForbidden();
        $this->actingAs($this->dispatcher)->post(route('online-orders.parcels.pack', $parcel), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->dispatcher)->post(route('online-orders.parcels.pack', $parcel), ['reason' => 'Label torn, AWB read by eye'])->assertRedirect();

        $parcel->refresh();
        $this->assertSame(ShipmentStatus::Packed, $parcel->status);
        $this->assertSame('manual', $parcel->pack_method);
        $this->assertSame('Label torn, AWB read by eye', $parcel->pack_note);

        // Once packed, only the office can cancel (and so unpack) it.
        $this->actingAs($this->agency)->post(route('online-orders.parcels.cancel', $parcel), ['reason' => 'Late cancel'])->assertForbidden();
    }

    #[Test]
    public function a_label_left_unpacked_after_the_cut_off_is_raised_until_it_is_packed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00', 'Asia/Kolkata'));
        $this->upload($this->agency, $this->rahatRooh, [$this->label('VL1000000000001'), $this->label('VL1000000000002', ['sku' => 'Unknown thing'])]);
        $this->mapOil();

        $exceptions = app(ExceptionService::class);
        $rules = fn () => $exceptions->detect()->map(fn (FactoryException $e) => $e->rule)->all();

        // Before the cut-off: only the parcel nobody can pack is raised.
        $this->assertSame(['parcels_blocked'], $rules());

        $this->travelTo(CarbonImmutable::parse('2026-09-24 16:30', 'Asia/Kolkata'));
        $late = $exceptions->detect()->firstWhere('rule', 'parcels_not_packed');
        $this->assertNotNull($late);
        $this->assertSame(FactoryException::HIGH, $late->severity);
        $this->assertStringContainsString('2 online order(s) not packed at Paper Market Depot', $late->title);

        // Still raised the next morning: it does not go away by itself.
        $this->travelTo(CarbonImmutable::parse('2026-09-25 09:00', 'Asia/Kolkata'));
        $this->assertContains('parcels_not_packed', $rules());

        $this->actingAs($this->packer)->postJson(route('floor.pack.scan'), ['code' => 'VL1000000000001'])->assertOk();
        $this->actingAs($this->dispatcher)->post(route('online-orders.parcels.cancel', Shipment::query()->where('awb', 'VL1000000000002')->sole()), ['reason' => 'Out of stock on listing'])->assertRedirect();

        $this->assertSame([], $rules());
    }

    #[Test]
    public function the_office_sets_where_a_brand_ships_from_and_who_uploads_for_it(): void
    {
        $factory = Facility::factory()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Rudrapur Factory', 'can_dispatch' => true]);
        $factoryFg = $factory->stores()->where('type', WarehouseType::FinishedGoods->value)->firstOrFail();
        $someoneElse = User::factory()->create();
        $someoneElse->assignRole(RoleName::StoreExecutive->value);

        $this->actingAs($this->dispatcher)->get(route('brands.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('online-orders/brands')->has('agencies', 1));

        $this->actingAs($this->dispatcher)->patch(route('brands.update', $this->cleanse), [
            'name' => 'Cleanse Ayurveda',
            'default_warehouse_id' => $factoryFg->id,
            'is_active' => true,
            'user_ids' => [$this->agency->id, $someoneElse->id],
        ])->assertRedirect();

        $this->cleanse->refresh();
        $this->assertSame($factoryFg->id, $this->cleanse->default_warehouse_id);
        // Only agency accounts are given brands.
        $this->assertSame([$this->agency->id], $this->cleanse->users()->pluck('users.id')->all());

        $this->actingAs($this->agency)->get(route('online-orders.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('brands', 2));
    }

    #[Test]
    public function a_parcel_label_scanned_in_the_general_scanner_says_what_it_is(): void
    {
        $this->upload($this->agency, $this->rahatRooh, [$this->label('VL1000000000001')]);

        $this->actingAs($this->packer)->postJson(route('floor.lookup'), ['code' => 'VL1000000000001'])
            ->assertOk()
            ->assertJsonPath('kind', 'parcel')
            ->assertJsonPath('actions.0.label', 'Pack it');
    }
}
