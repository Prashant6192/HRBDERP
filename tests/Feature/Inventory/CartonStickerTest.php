<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\CartonLabelPrint;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Models\Facility;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Satreetha 500 ml, 42 to a carton, ₹349 a piece: the QC manager prints a
 * sticker per carton, built from the product, the batch, the factory and
 * the brand, on the TSC or as A5 sheets.
 */
class CartonStickerTest extends TestCase
{
    use RefreshDatabase;

    private User $qc;

    private InventoryLot $lot;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->qc = User::factory()->create();
        $this->qc->assignRole(RoleName::StoreExecutive->value);

        Facility::factory()->manufacturing()->create([
            'name' => 'Rudrapur Manufacturing Facility', 'legal_name' => 'HRBD Enterprises Pvt Ltd',
            'address_line_1' => 'Plot 12, SIDCUL', 'city' => 'Rudrapur', 'state' => 'Uttarakhand', 'pincode' => '263153',
            'manufacturing_licence' => 'UK/COS/2024/0113',
        ]);

        Brand::query()->updateOrCreate(['code' => 'RR'], ['name' => 'Rahat Rooh', 'marketed_by' => 'Marketed by: HRBD Enterprises, Paper Market, Delhi 110006', 'consumer_care' => '1800-000-0000']);

        $ml = Uom::where('code', 'ML')->firstOrFail();
        $pcs = Uom::where('code', 'PCS')->firstOrFail();
        $this->product = Product::factory()->create([
            'name' => 'Satreetha Shampoo 500 ml', 'brand' => 'Rahat Rooh', 'stock_uom_id' => $pcs->id,
            'net_content' => '500', 'net_content_uom_id' => $ml->id, 'mrp' => '349', 'barcode' => '8901234567890',
        ]);
        $this->lot = InventoryLot::factory()->forItem($this->product)->create([
            'qc_status' => LotQcStatus::Approved, 'batch_number' => 'FG260927-041', 'initial_quantity' => '2016',
            'manufactured_at' => '2026-09-10', 'expiry_at' => '2028-08-31',
        ]);
    }

    private function plan(int $boxes = 48, int $units = 42): void
    {
        $this->actingAs($this->qc)->post(route('lots.cartons.store', $this->lot), [
            'boxes' => $boxes, 'units_per_box' => $units, 'gross_weight_kg' => '23.4', 'start_box' => 1,
        ])->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_sticker_fills_itself_in_from_the_journey(): void
    {
        $this->plan();

        $this->assertSame(42, $this->product->fresh()->units_per_carton, 'Pieces per carton are remembered on the product.');

        $this->actingAs($this->qc)->get(route('lots.cartons', $this->lot))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('lots/cartons')
                ->where('sticker.brand', 'Rahat Rooh')
                ->where('sticker.units', 42)
                ->where('sticker.net_per_unit', '500 ml')
                ->where('sticker.net_carton', '21 L')
                ->where('sticker.mrp_unit', '349.00')
                ->where('sticker.mrp_carton', '14,658.00')
                ->where('sticker.mfg', 'Sep 2026')
                ->where('sticker.expiry', 'Aug 2028')
                ->where('sticker.gross_weight', '23.4 kg')
                ->where('sticker.licence', 'UK/COS/2024/0113')
                ->where('sticker.manufacturer', 'HRBD Enterprises Pvt Ltd, Plot 12, SIDCUL, Rudrapur, Uttarakhand 263153')
                ->where('sticker.marketed_by', 'Marketed by: HRBD Enterprises, Paper Market, Delhi 110006')
                ->where('sticker.consumer_care', '1800-000-0000')
                ->where('sticker.last_box', 48)
                ->where('sticker.sample.code', 'CTN:FG260927-041/1/42'));

        // The next batch of the product starts from 42 to a carton.
        $next = InventoryLot::factory()->forItem($this->product)->create(['qc_status' => LotQcStatus::Approved, 'initial_quantity' => '420']);
        $this->actingAs($this->qc)->get(route('lots.cartons', $next))
            ->assertInertia(fn (Assert $page) => $page->where('suggested.units_per_box', 42)->where('suggested.boxes', 10));
    }

    #[Test]
    public function the_tsc_gets_a_100_by_150_program_for_the_boxes_asked_for(): void
    {
        $this->plan();

        $program = $this->actingAs($this->qc)->get(route('lots.cartons.tspl', ['lot' => $this->lot, 'from' => 3, 'to' => 5, 'copies' => 2]))
            ->assertOk()->getContent();

        $this->assertStringStartsWith("SIZE 100 mm,150 mm\r\n", $program);
        $this->assertSame(3, substr_count($program, 'PRINT 1,2'));
        $this->assertStringContainsString('CTN%3AFG260927-041%2F3%2F42', $program);
        $this->assertStringContainsString('CTN%3AFG260927-041%2F5%2F42', $program);
        $this->assertStringNotContainsString('CTN%3AFG260927-041%2F6%2F42', $program);
        $this->assertStringContainsString('"Rs. 14,658.00"', $program);
        $this->assertStringContainsString('"EAN13"', $program);
        $this->assertTrue(mb_check_encoding($program, 'ASCII'), 'The printer\'s fonts are plain ASCII.');

        $this->actingAs($this->qc)->postJson(route('lots.cartons.printed', $this->lot), ['printer' => 'TSC TE244', 'from' => 3, 'to' => 5, 'copies' => 2])->assertOk();

        $log = CartonLabelPrint::query()->sole();
        $this->assertSame(['tspl', 'TSC TE244', 3, 5, 2, $this->qc->id], [$log->format, $log->printer, $log->first_box, $log->last_box, $log->copies, $log->printed_by]);
    }

    #[Test]
    public function the_pdfs_print_through_a_driver_and_are_logged(): void
    {
        $this->plan(4);

        foreach (['sticker_pdf', 'a5'] as $format) {
            $response = $this->actingAs($this->qc)->get(route('lots.cartons.print', ['lot' => $this->lot, 'format' => $format]));
            $response->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }

        $this->assertSame(['sticker_pdf', 'a5'], CartonLabelPrint::query()->orderBy('id')->pluck('format')->all());
        $this->assertSame([1, 4], [CartonLabelPrint::query()->first()->first_box, CartonLabelPrint::query()->first()->last_box]);
    }

    #[Test]
    public function nothing_prints_before_qc_passes_or_for_someone_who_may_not_print(): void
    {
        $this->plan();

        $held = InventoryLot::factory()->forItem($this->product)->create(['qc_status' => LotQcStatus::Pending]);
        $this->actingAs($this->qc)->post(route('lots.cartons.store', $held), ['boxes' => 2, 'units_per_box' => 42])->assertSessionHasNoErrors();
        $this->actingAs($this->qc)->get(route('lots.cartons.tspl', $held))->assertStatus(422);

        $designer = User::factory()->create();
        $designer->assignRole(RoleName::Designer->value);
        $this->actingAs($designer)->get(route('lots.cartons.tspl', $this->lot))->assertForbidden();
        $this->actingAs($designer)->postJson(route('lots.cartons.printed', $this->lot), ['from' => 1, 'to' => 1])->assertForbidden();

        $this->assertSame(0, CartonLabelPrint::query()->count());
    }

    #[Test]
    public function the_licence_and_the_brand_lines_are_kept_where_they_are_entered(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::SuperAdmin->value);
        $facility = Facility::query()->firstOrFail();

        $this->actingAs($admin)->put(route('facilities.update', $facility), [
            'name' => $facility->name, 'facility_type_id' => $facility->facility_type_id,
            'legal_name' => 'HRBD Enterprises Private Limited', 'manufacturing_licence' => 'UK/COS/2026/0200',
        ])->assertSessionHasNoErrors();

        $facility->refresh();
        $this->assertSame('UK/COS/2026/0200', $facility->manufacturing_licence);
        $this->assertSame('HRBD Enterprises Private Limited', $facility->legal_name, 'The legal name is saved too.');

        $brand = Brand::query()->firstOrFail();
        $this->actingAs($admin)->patch(route('brands.update', $brand), [
            'name' => $brand->name, 'is_active' => true, 'user_ids' => [],
            'marketed_by' => 'Marketed by: Rahat Rooh, Delhi', 'consumer_care' => 'care@rahatrooh.com',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Marketed by: Rahat Rooh, Delhi', 'care@rahatrooh.com'], [$brand->fresh()->marketed_by, $brand->fresh()->consumer_care]);
    }
}
