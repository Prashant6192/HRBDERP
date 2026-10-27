<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\Enums\LabelReaderKind;
use App\Domain\Marketplace\Enums\PaymentMode;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Readers\ShiprocketLabelParser;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
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
 * Website orders are shipped through Shiprocket: the courier is chosen
 * there and its labels are uploaded here under "Website", read like any
 * marketplace's, printed as they are (already label-sized). All names and
 * numbers are made up.
 */
class WebsiteOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $depotFg;

    private User $office;

    /**
     * @return array<string, mixed>
     */
    private function parcel(string $awb, array $overrides = []): array
    {
        return [
            'awb' => $awb, 'courier' => 'Delhivery', 'payment' => 'cod', 'sku' => 'RR-MED-500', 'description' => 'Rahat Rooh Medicated Oil 500ml',
            'qty' => 2, 'order' => '1042', 'total' => '798.00', 'name' => 'Test Customer', 'state' => 'Bihar', 'pincode' => '800001', ...$overrides,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('files');
        $this->app->instance(AiLabelReader::class, new FakeLabelReader(available: false));

        $depot = Facility::factory()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Paper Market Facility', 'can_manufacture' => false, 'can_dispatch' => true]);
        $this->depotFg = $depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        $this->seed(ReferenceDataSeeder::class);

        $this->office = User::factory()->create();
        $this->office->assignRole(RoleName::SuperAdmin->value);
    }

    #[Test]
    public function website_is_offered_on_upload_and_reads_shiprocket_labels(): void
    {
        $website = Marketplace::query()->where('code', 'WEBSITE')->sole();
        $this->assertSame(LabelReaderKind::Shiprocket, $website->reader);
        $this->assertFalse($website->reader->labelAboveInvoice());

        $this->actingAs($this->office)->get(route('online-orders.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('marketplaces', fn ($list) => collect($list)->contains(fn ($m) => $m['name'] === 'Website' && $m['reads'] === 'Shiprocket label text')));
    }

    #[Test]
    public function a_shiprocket_label_is_read_field_by_field(): void
    {
        $parcel = (new ShiprocketLabelParser)->parse(LabelFixtures::shiprocketPageText($this->parcel('14327000000123')), 1);

        $this->assertNotNull($parcel);
        $this->assertSame('14327000000123', $parcel->awb);
        $this->assertSame('1042', $parcel->orderNumber);
        $this->assertSame('Delhivery', $parcel->courier);
        $this->assertSame(PaymentMode::Cod, $parcel->paymentMode);
        $this->assertSame('798.00', $parcel->payableAmount);
        $this->assertSame('Test Customer', $parcel->customerName);
        $this->assertSame('Bihar', $parcel->customerState);
        $this->assertSame('800001', $parcel->customerPincode);
        $this->assertSame([['seller_sku' => 'RR-MED-500', 'description' => 'Rahat Rooh Medicated Oil 500ml', 'quantity' => 2]], $parcel->lines);
        $this->assertSame([], $parcel->warnings);

        // Prepaid: nothing to collect.
        $prepaid = (new ShiprocketLabelParser)->parse(LabelFixtures::shiprocketPageText($this->parcel('SF123456789FPL', ['payment' => 'prepaid', 'courier' => 'Shadowfax'])), 1);
        $this->assertSame(PaymentMode::Prepaid, $prepaid->paymentMode);
        $this->assertSame('Shadowfax', $prepaid->courier);

        // A page with no AWB is not a label.
        $this->assertNull((new ShiprocketLabelParser)->parse("Shipping manifest\nTotal parcels: 4", 1));
    }

    #[Test]
    public function an_uploaded_shiprocket_file_becomes_parcels(): void
    {
        $batch = app(OnlineOrderService::class)->upload(
            Brand::query()->where('code', 'RR')->sole(),
            Marketplace::query()->where('code', 'WEBSITE')->sole(),
            $this->depotFg,
            [LabelFixtures::shiprocket([$this->parcel('14327000000123'), $this->parcel('14327000000124', ['order' => '1043', 'qty' => 1])])],
            $this->office,
        );

        $this->assertSame(2, $batch->shipments()->count());
        $first = Shipment::query()->where('awb', '14327000000123')->with('lines')->sole();
        $this->assertSame(['1042', 'Delhivery', [1]], [$first->order_number, $first->courier, $first->pages]);
        $this->assertSame([['RR-MED-500', 2]], $first->lines->map(fn ($l) => [$l->seller_sku, $l->quantity])->all());
    }

    #[Test]
    public function a_meesho_file_under_website_is_refused(): void
    {
        $this->expectExceptionMessage('looks like Meesho labels');

        app(OnlineOrderService::class)->upload(
            Brand::query()->where('code', 'RR')->sole(),
            Marketplace::query()->where('code', 'WEBSITE')->sole(),
            $this->depotFg,
            [LabelFixtures::meesho([[
                'awb' => 'VL1000000000001', 'courier' => 'Valmo', 'payment' => 'cod', 'sku' => 'Oil', 'qty' => 1,
                'order' => '200000000000000001', 'invoice' => 'abcde0001', 'total' => '199.00', 'name' => 'A', 'state' => 'Delhi',
            ]])],
            $this->office,
        );
    }
}
