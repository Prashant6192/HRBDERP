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
 * there and its labels, two to an A4 page, are uploaded here under
 * "Website". Each label becomes its own parcel and prints on its own. All
 * names and numbers are made up.
 */
class WebsiteOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $depotFg;

    private User $office;

    /**
     * @return array<string, mixed>
     */
    private function parcel(string $awb, string $order, array $overrides = []): array
    {
        return [
            'awb' => $awb, 'courier' => 'Instant pickup - Shadowfax', 'payment' => 'cod', 'order' => $order, 'total' => '998',
            'name' => 'Test Customer', 'state' => 'Maharashtra', 'pincode' => '443301',
            'items' => [['sku' => 'RR-MED-500 (500ML-2PacK)', 'item' => 'Rahat Rooh Medicated Oil - ...', 'qty' => 1, 'price' => '899.00']],
            ...$overrides,
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
    public function both_labels_on_a_page_are_read_field_by_field(): void
    {
        $text = LabelFixtures::shiprocketLabelText($this->parcel('SF4300000001SPF', '2844'))."\n"
            .LabelFixtures::shiprocketLabelText($this->parcel('14326000000001', '2845', [
                'courier' => 'Xpressbees Surface 2kg', 'total' => '454', 'state' => 'Haryana', 'pincode' => '121102',
                'items' => [['sku' => 'RR-SAT-SH-002 (1000ML)', 'item' => 'Rahat Rooh Satreetha Shampoo - 1000ml', 'qty' => 1, 'price' => '355.00']],
            ]));

        [$left, $right] = (new ShiprocketLabelParser)->parsePage($text, 1);

        $this->assertSame([0, 'SF4300000001SPF', '2844', 'Shadowfax'], [$left->slot, $left->awb, $left->orderNumber, $left->courier]);
        $this->assertSame([PaymentMode::Cod, '998.00'], [$left->paymentMode, $left->payableAmount]);
        $this->assertSame(['Test Customer', 'Maharashtra', '443301'], [$left->customerName, $left->customerState, $left->customerPincode]);
        // The SKU that ran past its column is put back together.
        $this->assertSame([['seller_sku' => 'RR-MED-500 (500ML-2PacK)', 'description' => 'Rahat Rooh Medicated Oil', 'quantity' => 1]], $left->lines);
        $this->assertSame(['Retail02844', '2026-10-27', '07AAAAA0000A1Z5'], [$left->invoiceNumber, $left->invoiceDate, $left->sellerGstin]);

        $this->assertSame([1, '14326000000001', '2845', 'Xpress Bees', 'Haryana'], [$right->slot, $right->awb, $right->orderNumber, $right->courier, $right->customerState]);
        $this->assertSame([['seller_sku' => 'RR-SAT-SH-002 (1000ML)', 'description' => 'Rahat Rooh Satreetha Shampoo - 1000ml', 'quantity' => 1]], $right->lines);
    }

    #[Test]
    public function a_label_with_two_products_and_a_prepaid_one_are_read(): void
    {
        $two = (new ShiprocketLabelParser)->parse(LabelFixtures::shiprocketLabelText($this->parcel('1904000000001', '2846', [
            'courier' => 'Delhivery Surface 2 Kgs', 'total' => '1,338',
            'items' => [
                ['sku' => 'RR-SUK-300 (300ML)', 'item' => 'Rahat Rooh Sukoon Oil - 300ml', 'qty' => 1, 'price' => '439.00'],
                ['sku' => 'RR-MED-500 (500ML-2PacK)', 'item' => 'Rahat Rooh Medicated Oil - ...', 'qty' => 1, 'price' => '899.00'],
            ],
        ])), 2);

        $this->assertSame('Delhivery', $two->courier);
        $this->assertSame('1338.00', $two->payableAmount);
        $this->assertSame([['RR-SUK-300 (300ML)', 1], ['RR-MED-500 (500ML-2PacK)', 1]], array_map(fn ($l) => [$l['seller_sku'], $l['quantity']], $two->lines));
        $this->assertSame([], $two->warnings);

        $prepaid = (new ShiprocketLabelParser)->parse(LabelFixtures::shiprocketLabelText($this->parcel('1904000000002', '2847', ['payment' => 'prepaid'])), 1);
        $this->assertSame([PaymentMode::Prepaid, '0.00'], [$prepaid->paymentMode, $prepaid->payableAmount]);

        // A page with no AWB is not a label.
        $this->assertSame([], (new ShiprocketLabelParser)->parsePage("Shipping manifest\nTotal parcels: 4", 1));
    }

    #[Test]
    public function an_uploaded_file_becomes_one_parcel_per_label_each_printed_on_its_own(): void
    {
        $batch = app(OnlineOrderService::class)->upload(
            Brand::query()->where('code', 'RR')->sole(),
            Marketplace::query()->where('code', 'WEBSITE')->sole(),
            $this->depotFg,
            [LabelFixtures::shiprocket([
                $this->parcel('SF4300000001SPF', '2844'),
                $this->parcel('14326000000001', '2845', ['courier' => 'Xpressbees Surface 2kg']),
                $this->parcel('1904000000001', '2846', ['courier' => 'Delhivery Surface 2 Kgs']),
            ])],
            $this->office,
        );

        $this->assertSame(3, $batch->shipments()->count());
        $this->assertSame(
            [['SF4300000001SPF', [1], 0], ['14326000000001', [1], 1], ['1904000000001', [2], 0]],
            Shipment::query()->orderBy('id')->get()->map(fn (Shipment $s) => [$s->awb, $s->pages, $s->page_slot])->all(),
        );

        $plan = app(OnlineOrderService::class)->print($batch, 'all', $this->office);
        $this->assertEqualsCanonicalizing([0, 1, 0], array_column($plan['parts'], 'slot'));
        $this->assertSame([false, false, false], array_column($plan['parts'], 'crop'));
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
