<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\LabelBatch;
use App\Domain\Marketplace\Models\LabelFile;
use App\Domain\Marketplace\Models\LabelPrint;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeLabelReader;
use Tests\Support\LabelFixtures;
use Tests\TestCase;

/**
 * The day's screen prints across batches: tick labels one by one, or a
 * courier at a time, and they come out in courier order and are marked
 * printed.
 */
class PrintSelectedLabelsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $depotFg;

    private User $dispatcher;

    private User $agency;

    private LabelBatch $rr;

    private LabelBatch $ca;

    private string $rrPdf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('files');
        $this->app->instance(AiLabelReader::class, new FakeLabelReader(available: false));
        config(['erp.company.timezone' => 'Asia/Kolkata']);

        $depot = Facility::factory()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Paper Market Warehouse', 'can_manufacture' => false, 'can_dispatch' => true]);
        $this->depotFg = $depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        $this->seed(ReferenceDataSeeder::class);

        $this->dispatcher = User::factory()->create();
        $this->dispatcher->assignRole(RoleName::DispatchManager->value);
        $this->agency = User::factory()->create();
        $this->agency->assignRole(RoleName::EcommerceAgency->value);

        $meesho = Marketplace::query()->where('code', 'MEESHO')->sole();
        $orders = app(OnlineOrderService::class);
        $label = fn (string $awb, string $courier, int $n) => [
            'awb' => $awb, 'courier' => $courier, 'payment' => 'cod', 'sku' => 'Hair oil 200 ml', 'qty' => 1,
            'order' => sprintf('20000000000%04d', $n), 'invoice' => sprintf('abcde%04d', $n), 'total' => '199.00', 'name' => "Customer {$n}", 'state' => 'Delhi',
        ];

        $rrFile = LabelFixtures::meesho([
            $label('VL0000000000001', 'Valmo', 1),
            $label('DL0000000000002', 'Delhivery', 2),
            $label('VL0000000000003', 'Valmo', 3),
        ], 'rr.pdf');
        $this->rrPdf = (string) file_get_contents($rrFile->getRealPath());
        $this->rr = $orders->upload(Brand::query()->where('code', 'RR')->sole(), $meesho, $this->depotFg, [$rrFile], $this->agency);
        $this->ca = $orders->upload(Brand::query()->where('code', 'CA')->sole(), $meesho, $this->depotFg, [LabelFixtures::meesho([
            $label('DL0000000000004', 'Delhivery', 4),
            $label('VL0000000000005', 'Valmo', 5),
        ], 'ca.pdf')], $this->agency);
    }

    private function id(string $awb): int
    {
        return Shipment::query()->where('awb', $awb)->sole()->id;
    }

    #[Test]
    public function every_valmo_label_across_the_days_batches_prints_in_one_go(): void
    {
        $valmo = Shipment::query()->where('courier', 'Valmo')->pluck('id')->all();

        $plan = $this->actingAs($this->dispatcher)->postJson(route('online-orders.print-selected'), ['shipment_ids' => $valmo])
            ->assertOk()
            ->json();

        $this->assertSame(3, $plan['shipments']);
        $this->assertCount(2, $plan['files'], 'Pages come from both batches\' files.');
        $this->assertSame(['Valmo', 'Valmo', 'Valmo'], array_column($plan['parts'], 'courier'));

        $this->assertSame(3, Shipment::query()->where('status', ShipmentStatus::Printed->value)->count());
        $this->assertSame(2, Shipment::query()->where('status', ShipmentStatus::Uploaded->value)->where('courier', 'Delhivery')->count());

        // Logged against each batch, as a courier run.
        $this->assertEqualsCanonicalizing(
            [[$this->rr->id, 'courier', 'Valmo', 2], [$this->ca->id, 'courier', 'Valmo', 1]],
            LabelPrint::query()->get()->map(fn (LabelPrint $p) => [$p->label_batch_id, $p->scope, $p->courier, $p->shipment_count])->all(),
        );
    }

    #[Test]
    public function a_mixed_pick_comes_out_courier_by_courier_and_skips_what_cannot_print(): void
    {
        $packed = $this->id('VL0000000000005');
        Shipment::query()->whereKey($packed)->update(['status' => ShipmentStatus::Packed->value]);

        $plan = $this->actingAs($this->dispatcher)->postJson(route('online-orders.print-selected'), [
            'shipment_ids' => [$this->id('VL0000000000001'), $this->id('DL0000000000004'), $this->id('DL0000000000002'), $packed],
        ])->assertOk()->json();

        $this->assertSame(['Delhivery', 'Delhivery', 'Valmo'], array_column($plan['parts'], 'courier'));
        $this->assertSame(ShipmentStatus::Packed, Shipment::query()->find($packed)->status);
        $this->assertSame(['selected'], LabelPrint::query()->distinct()->pluck('scope')->all());

        // A reprint counts again but stays printed.
        $this->actingAs($this->dispatcher)->postJson(route('online-orders.print-selected'), ['shipment_ids' => [$this->id('VL0000000000001')]])->assertOk();
        $this->assertSame(2, Shipment::query()->find($this->id('VL0000000000001'))->print_count);

        $this->actingAs($this->dispatcher)->postJson(route('online-orders.print-selected'), ['shipment_ids' => [$packed]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'None of those labels can be printed: they are packed, with the courier or cancelled.');
    }

    #[Test]
    public function the_agency_does_not_print(): void
    {
        $this->actingAs($this->agency)->postJson(route('online-orders.print-selected'), ['shipment_ids' => [$this->id('VL0000000000001')]])
            ->assertForbidden();
        $this->assertSame(0, LabelPrint::query()->count());
    }

    #[Test]
    public function a_label_file_wiped_from_the_disk_by_a_deploy_is_served_from_the_database(): void
    {
        $file = $this->rr->files()->sole();
        Storage::disk('files')->delete($file->path);

        $response = $this->actingAs($this->dispatcher)->get(route('online-orders.files.show', $file))->assertOk();
        $this->assertSame($this->rrPdf, $response->streamedContent());
        $this->assertTrue(Storage::disk('files')->exists($file->path), 'Put back on the disk.');
    }

    #[Test]
    public function a_file_lost_before_the_fix_stops_printing_until_the_same_pdf_is_uploaded_again(): void
    {
        $file = $this->rr->files()->sole();
        Storage::disk('files')->delete($file->path);
        DB::table('label_file_contents')->where('label_file_id', $file->id)->delete();

        $ids = Shipment::query()->where('label_batch_id', $this->rr->id)->pluck('id')->all();
        $this->actingAs($this->dispatcher)->postJson(route('online-orders.print-selected'), ['shipment_ids' => $ids])
            ->assertStatus(422)
            ->assertJsonPath('message', "The label file rr.pdf ({$this->rr->number}) is missing from the server. Upload the same PDF again (Online orders → Upload labels) to put it back — no orders are added twice — then print.");
        $this->assertSame(0, Shipment::query()->where('status', ShipmentStatus::Printed->value)->count(), 'Nothing is marked printed.');
        $this->actingAs($this->dispatcher)->get(route('online-orders.files.show', $file))->assertNotFound();

        // The agency uploads the same PDF again: put back, not refused, nothing doubled.
        $this->agency->brands()->attach(Brand::query()->where('code', 'RR')->sole());
        $this->actingAs($this->agency)->post(route('online-orders.store'), [
            'brand_id' => $this->rr->brand_id,
            'marketplace_id' => $this->rr->marketplace_id,
            'files' => [UploadedFile::fake()->createWithContent('rr.pdf', $this->rrPdf)],
        ])->assertRedirect(route('online-orders.show', $this->rr->id))->assertSessionHasNoErrors();

        $this->assertSame(5, Shipment::query()->count());
        $this->assertSame(2, LabelFile::query()->count());

        $this->actingAs($this->dispatcher)->postJson(route('online-orders.print-selected'), ['shipment_ids' => $ids])->assertOk();
        $this->assertSame(3, Shipment::query()->where('status', ShipmentStatus::Printed->value)->count());
    }
}
