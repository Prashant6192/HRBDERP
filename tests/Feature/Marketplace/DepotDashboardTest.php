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
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\LabelBatch;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\EmployeeAssignmentService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeLabelReader;
use Tests\Support\LabelFixtures;
use Tests\TestCase;

/**
 * The depot opens on its own day: it waits while the agency uploads, and
 * once the agency closes the day it says how many parcels to pack, with
 * one button to start. The factory's materials are not its business.
 */
class DepotDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Facility $depot;

    private Facility $factory;

    private Brand $rahatRooh;

    private Marketplace $meesho;

    private User $agency;

    private User $dispatcher;

    private User $packer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('files');
        $this->app->instance(AiLabelReader::class, new FakeLabelReader(available: false));
        config(['erp.online_orders.cutoff' => '16:00', 'erp.company.timezone' => 'Asia/Kolkata']);
        $this->travelTo(CarbonImmutable::parse('2026-09-28 10:30', 'Asia/Kolkata'));

        $this->factory = Facility::factory()->manufacturing()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Rudrapur Manufacturing Facility']);
        $this->depot = Facility::factory()->withStores([WarehouseType::FinishedGoods])
            ->create(['name' => 'Paper Market Warehouse', 'can_manufacture' => false, 'can_dispatch' => true]);
        $this->seed(ReferenceDataSeeder::class);

        $this->rahatRooh = Brand::query()->where('code', 'RR')->sole();
        $this->meesho = Marketplace::query()->where('code', 'MEESHO')->sole();

        $this->agency = User::factory()->create(['name' => 'Divrit Digital']);
        $this->agency->assignRole(RoleName::EcommerceAgency->value);
        $this->agency->brands()->attach($this->rahatRooh);

        $this->dispatcher = User::factory()->create(['name' => 'Dispatch Desk']);
        $this->dispatcher->assignRole(RoleName::DispatchManager->value);

        $this->packer = User::factory()->create(['name' => 'Packing Table']);
        $this->packer->assignRole(RoleName::StoreExecutive->value);
        app(EmployeeAssignmentService::class)->assign($this->packer, $this->depot, null, ['is_primary' => true], null);
    }

    private function upload(int $parcels): LabelBatch
    {
        $labels = [];
        for ($i = 1; $i <= $parcels; $i++) {
            $labels[] = [
                'awb' => sprintf('VL10000000%05d', $i), 'courier' => 'Valmo', 'payment' => 'cod', 'sku' => 'Hair oil 200 ml', 'qty' => 1,
                'order' => sprintf('2000000000000%05d', $i), 'invoice' => sprintf('fghij%04d', $i), 'total' => '199.00',
                'name' => "Test Customer {$i}", 'state' => 'Bihar',
            ];
        }

        $this->actingAs($this->agency)->post(route('online-orders.store'), [
            'brand_id' => $this->rahatRooh->id,
            'marketplace_id' => $this->meesho->id,
            'files' => [LabelFixtures::meesho($labels, 'labels.pdf')],
        ])->assertRedirect();

        return LabelBatch::query()->latest('id')->firstOrFail();
    }

    private function dashboard(User $as, ?Facility $facility = null)
    {
        return $this->actingAs($as)->get(route('dashboard', $facility === null ? [] : ['facility' => $facility->id]))->assertOk();
    }

    #[Test]
    public function the_depot_waits_for_the_agency_then_says_how_many_to_pack(): void
    {
        $this->dashboard($this->dispatcher, $this->depot)->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('depot.date', '2026-09-28')
            ->where('depot.online.state', 'waiting')
            ->where('depot.online.to_pack', 0));

        $batch = $this->upload(3);

        $this->dashboard($this->dispatcher, $this->depot)->assertInertia(fn (Assert $page) => $page
            ->where('depot.online.state', 'uploading')
            ->where('depot.online.total', 3)
            ->where('depot.online.open_uploads', 1)
            ->where('depot.online.uploaded_by', ['Divrit Digital'])
            ->where('depot.online.finished_at', null));

        $this->actingAs($this->agency)->post(route('online-orders.close', $batch))->assertRedirect();

        $this->dashboard($this->dispatcher, $this->depot)->assertInertia(fn (Assert $page) => $page
            ->where('depot.online.state', 'ready')
            ->where('depot.online.to_pack', 3)
            ->where('depot.online.packed', 0)
            ->where('depot.online.open_uploads', 0)
            ->where('depot.online.finished_at', $batch->refresh()->closed_at->toIso8601String())
            ->where('depot.online.batches.0.number', $batch->number)
            ->where('depot.online.couriers.0', ['courier' => 'Valmo', 'n' => 3]));

        Shipment::query()->where('label_batch_id', $batch->id)->update(['status' => ShipmentStatus::Packed->value]);

        $this->dashboard($this->dispatcher, $this->depot)->assertInertia(fn (Assert $page) => $page
            ->where('depot.online.state', 'done')
            ->where('depot.online.to_pack', 0)
            ->where('depot.online.packed', 3));
    }

    #[Test]
    public function yesterdays_labels_are_not_todays_work(): void
    {
        $batch = $this->upload(2);
        $this->actingAs($this->agency)->post(route('online-orders.close', $batch))->assertRedirect();

        $this->travelTo(CarbonImmutable::parse('2026-09-29 09:00', 'Asia/Kolkata'));

        $this->dashboard($this->dispatcher, $this->depot)->assertInertia(fn (Assert $page) => $page
            ->where('depot.date', '2026-09-29')
            ->where('depot.online.state', 'waiting'));
    }

    #[Test]
    public function the_factory_keeps_its_own_dashboard(): void
    {
        $this->dashboard($this->dispatcher, $this->factory)->assertInertia(fn (Assert $page) => $page
            ->where('facility.id', $this->factory->id)
            ->where('depot', null));

        // Across every place: the company view, not a depot's day.
        $this->dashboard($this->dispatcher)->assertInertia(fn (Assert $page) => $page->where('depot', null));
    }

    #[Test]
    public function someone_who_works_only_at_the_depot_opens_on_it(): void
    {
        $this->dashboard($this->packer)->assertInertia(fn (Assert $page) => $page
            ->where('facility.id', $this->depot->id)
            ->where('depot.online.state', 'waiting'));
    }

    #[Test]
    public function management_on_a_phone_sees_the_depot_stock_and_its_online_orders(): void
    {
        $oil = Product::factory()->create(['name' => 'Rahat Rooh Hair Oil 200 ml', 'stock_uom_id' => Uom::where('code', 'PCS')->sole()->id, 'standard_cost' => '80', 'reorder_level' => '100']);
        $lot = InventoryLot::factory()->forItem($oil)->create(['qc_status' => LotQcStatus::Approved, 'expiry_at' => now()->addYear(), 'unit_cost' => '80']);
        $depotFg = $this->depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        app(InventoryLedgerService::class)->receive($oil, $depotFg, '50', $lot, InventoryTransactionType::StockAdjustmentIn);

        $batch = $this->upload(4);
        $this->actingAs($this->agency)->post(route('online-orders.close', $batch))->assertRedirect();
        Shipment::query()->where('label_batch_id', $batch->id)->limit(1)->get()
            ->each(fn (Shipment $s) => $s->forceFill(['status' => ShipmentStatus::HandedOver, 'packed_at' => now(), 'handed_over_at' => now()])->save());

        $boss = User::factory()->create();
        $boss->assignRole(RoleName::Management->value);

        $this->actingAs($boss)->get(route('management.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('management/index')
                ->has('overview.depots', 1)
                ->where('overview.depots.0.short', 'Paper Market')
                ->where('overview.depots.0.stock.value', '4000.00')
                ->where('overview.depots.0.stock.units', '50')
                ->where('overview.depots.0.stock.below_reorder', 1)
                ->where('overview.depots.0.online.state', 'ready')
                ->where('overview.depots.0.online.to_pack', 3)
                ->where('overview.depots.0.week.6', ['date' => '2026-09-28', 'shipped' => 1])
                ->where('overview.depots.0.dispatches.pending', 0));

        $this->actingAs($boss)->get(route('management.depot'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('management/depot')
                ->where('depot.id', $this->depot->id)
                ->where('depot.items.0.name', 'Rahat Rooh Hair Oil 200 ml')
                ->where('depot.online.batches.0.number', $batch->number)
                ->has('depots', 1));

        // The stock tab can be narrowed to one place.
        $this->actingAs($boss)->get(route('management.materials', ['type' => 'finished_good', 'facility' => $this->depot->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('place', $this->depot->id)
                ->has('places', 2)
                ->where('items.0.name', 'Rahat Rooh Hair Oil 200 ml')
                ->where('total_value', '4000.00'));

        $this->actingAs($boss)->get(route('management.materials', ['type' => 'finished_good', 'facility' => $this->factory->id]))
            ->assertInertia(fn (Assert $page) => $page->where('items', [])->where('total_value', '0.00'));
    }
}
