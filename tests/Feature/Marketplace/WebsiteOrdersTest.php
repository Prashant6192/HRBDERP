<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\DTOs\BrowserPages;
use App\Domain\Marketplace\Enums\LabelReaderKind;
use App\Domain\Marketplace\Enums\PaymentMode;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\LabelFile;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Readers\ShiprocketLabelParser;
use App\Domain\Marketplace\Services\LabelReaderService;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            ->assertInertia(fn (Assert $page) => $page->where('marketplaces', fn ($list) => collect($list)->contains(fn ($m) => $m['name'] === 'Website' && $m['reads'] === 'Shiprocket labels and invoices' && $m['two_files'] === true && $m['reads_in_browser'] === true)));
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

    /**
     * Upload files under Website, with what the browser read on the labels
     * file's picture pages.
     *
     * @param  list<UploadedFile>  $files
     * @param  array<int, array{text: string, codes: list<array{format: string, text: string}>}>  $pictures  page => reading, for the first file
     */
    private function uploadWebsite(array $files, array $pictures = []): void
    {
        $seen = $pictures === [] ? [] : [hash_file('sha256', $files[0]->getRealPath()) => BrowserPages::fromJson((string) json_encode([
            'pages' => array_map(fn (int $page, array $p) => ['page' => $page, ...$p], array_keys($pictures), $pictures),
        ]))];

        app(OnlineOrderService::class)->upload(
            Brand::query()->where('code', 'RR')->sole(),
            Marketplace::query()->where('code', 'WEBSITE')->sole(),
            $this->depotFg,
            $files,
            $this->office,
            seen: $seen,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function invoice(string $awb, string $order, array $overrides = []): array
    {
        return [
            'awb' => $awb, 'order' => $order, 'invoice' => 'Retail0'.$order, 'courier' => 'Xpressbees Surface 2kg', 'payment' => 'cod',
            'name' => 'Test Customer', 'state' => 'Maharashtra', 'pincode' => '443301', 'total' => '254.00',
            'items' => [['sku' => '67960000000001', 'item' => 'Rahat Rooh Lauki Oil - 200ml', 'qty' => 1, 'price' => '155.00']],
            ...$overrides,
        ];
    }

    #[Test]
    public function a_numeric_store_sku_is_read_apart_from_its_product_name(): void
    {
        $label = (new ShiprocketLabelParser)->parsePage(LabelFixtures::shiprocketLabelText($this->parcel('14300000000001', '3101', [
            'items' => [['sku' => '67960000000001', 'item' => 'Rahat Rooh Lauki Oil Herbal Hair Oil for Strong, Thick & Healthy Hai...', 'qty' => 1, 'price' => '155.00']],
        ])), 1)[0];

        $this->assertSame('67960000000001', $label->lines[0]['seller_sku']);
        $this->assertStringStartsWith('Rahat Rooh Lauki Oil', (string) $label->lines[0]['description']);
    }

    #[Test]
    public function the_invoice_file_completes_the_picture_labels_and_stays_off_the_label_printer(): void
    {
        $picture = ['awb' => '375300000001', 'order' => '3102', 'invoice' => 'Retail03102', 'name' => 'Asha Test', 'pincode' => '226001'];

        $this->uploadWebsite([
            LabelFixtures::websiteLabels(1, [
                $this->parcel('14300000000001', '3101', ['courier' => 'Xpressbees Surface 2kg', 'items' => [['sku' => '67960000000001', 'item' => 'Lauki Oil - 200ml', 'qty' => 1, 'price' => '155.00']]]),
                $this->parcel('SF4300000002SPF', '3103'),
            ]),
            LabelFixtures::websiteInvoices([
                $this->invoice('14300000000001', '3101'),
                $this->invoice('375300000001', '3102', ['courier' => 'Amazon COD Surface 500gm', 'total' => '597.00', 'name' => 'Asha Test', 'state' => 'Uttar Pradesh', 'pincode' => '226001',
                    'items' => [['sku' => 'RR-SAT-SH-002 (500ML-2PacK)', 'item' => 'Rahat Rooh Satreetha Shampoo - 500ml', 'qty' => 2, 'price' => '298.50']]]),
                $this->invoice('SF4300000002SPF', '3103', ['courier' => 'Instant pickup - Shadowfax', 'total' => '998.00',
                    'items' => [['sku' => 'RR-MED-500 (500ML-2PacK)', 'item' => 'Rahat Rooh Medicated Oil', 'qty' => 1, 'price' => '899.00']]]),
            ]),
        ], [1 => LabelFixtures::websitePictureSeen($picture)]);

        $invoices = LabelFile::query()->where('original_name', 'invoices.pdf')->sole();
        $this->assertSame(3, Shipment::query()->count(), 'The invoices complete the labels; they add no parcels.');
        $this->assertSame(3, Shipment::query()->where('invoice_file_id', $invoices->id)->count());

        $amazon = Shipment::query()->where('awb', '375300000001')->sole();
        $this->assertSame(['Amazon Shipping', '3102', PaymentMode::Cod, '597.00'], [$amazon->courier, $amazon->order_number, $amazon->payment_mode, number_format((float) $amazon->payable_amount, 2, '.', '')]);
        $this->assertSame([['RR-SAT-SH-002 (500ML-2PacK)', '2']], $amazon->lines->map(fn ($l) => [$l->seller_sku, (string) (int) $l->quantity])->all());
        $this->assertSame([], array_values(array_filter($amazon->warnings ?? [], fn (string $w) => str_starts_with($w, 'Waiting'))));
        $this->assertSame([2], $amazon->invoice_pages);

        // A text label keeps its own reading of the product.
        $this->assertSame(['67960000000001'], Shipment::query()->where('awb', '14300000000001')->sole()->lines->pluck('seller_sku')->all());

        $batch = Shipment::query()->where('awb', '375300000001')->sole()->batch;
        $plan = app(OnlineOrderService::class)->print($batch, 'all', $this->office);
        $this->assertCount(3, $plan['parts'], 'One label each: the A4 invoices are not sent to the label printer.');
        $this->assertNotContains($invoices->id, array_column($plan['parts'], 'file_id'));
        $this->assertSame(['Amazon Shipping', 'Shadowfax', 'Xpress Bees'], array_column($plan['parts'], 'courier'));
    }

    #[Test]
    public function invoices_uploaded_before_their_labels_wait_for_them(): void
    {
        $this->uploadWebsite([LabelFixtures::websiteInvoices([$this->invoice('14300000000001', '3101')], 'early-invoices.pdf')]);

        $parcel = Shipment::query()->sole();
        $this->assertSame('14300000000001', $parcel->awb);
        $this->assertStringStartsWith('Waiting for the label file', (string) collect($parcel->warnings)->last());

        $this->uploadWebsite([LabelFixtures::websiteLabels(0, [$this->parcel('14300000000001', '3101', ['courier' => 'Xpressbees Surface 2kg'])])]);

        $parcel = Shipment::query()->sole()->refresh();
        $this->assertSame(LabelFile::query()->where('original_name', 'final_labels.pdf')->sole()->id, $parcel->label_file_id);
        $this->assertSame([1], $parcel->pages);
        $this->assertSame(0, $parcel->page_slot);
        $this->assertSame([], array_values(array_filter($parcel->warnings ?? [], fn (string $w) => str_starts_with($w, 'Waiting'))));
    }

    #[Test]
    public function a_long_file_the_server_cannot_read_is_not_sent_to_the_ai_reader_in_one_request(): void
    {
        $reader = new FakeLabelReader([]);
        $this->app->instance(AiLabelReader::class, $reader);

        $this->uploadWebsite([LabelFixtures::imageOnly(LabelReaderService::AI_MAX_PAGES + 1, 'website-pictures.pdf')]);

        $this->assertSame([], $reader->calls);
        $this->assertSame(LabelReaderService::AI_MAX_PAGES + 1, Shipment::query()->count());
        $this->assertStringContainsString('too long', LabelFile::query()->sole()->warnings[0]);
    }

    #[Test]
    public function the_mapping_screen_reads_each_sku_in_plain_words_and_suggests_its_product(): void
    {
        $pcs = Uom::where('code', 'PCS')->sole()->id;
        Product::factory()->create(['name' => 'Rahat Rooh Lauki Oil 300ml', 'stock_uom_id' => $pcs]);
        $lauki200 = Product::factory()->create(['name' => 'Rahat Rooh Lauki Oil 200ml', 'stock_uom_id' => $pcs]);

        // The label cuts the title short and prints no size; the invoice
        // prints it whole.
        $this->uploadWebsite([
            LabelFixtures::websiteLabels(0, [$this->parcel('14300000000001', '3101', ['courier' => 'Xpressbees Surface 2kg',
                'items' => [['sku' => '67960000000001', 'item' => 'Rahat Rooh Lauki Oil Herbal Hair Oil for Strong, Thick & Healthy Hai...', 'qty' => 1, 'price' => '155.00']]])]),
            LabelFixtures::websiteInvoices([$this->invoice('14300000000001', '3101', [
                'items' => [['sku' => '67960000000001', 'item' => 'Rahat Rooh Lauki Oil Herbal Hair Oil for Strong, Thick & Healthy Hair - 200ml', 'qty' => 1, 'price' => '155.00']],
            ])]),
        ]);

        $this->actingAs($this->office)->get(route('listings.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('waiting.0.seller_sku', '67960000000001')
                ->where('waiting.0.reading.name', 'Lauki Oil Herbal Hair Oil')
                ->where('waiting.0.reading.size', '200 ml')
                ->where('waiting.0.suggested.item_id', (string) $lauki200->id)
                ->where('waiting.0.suggested.pieces', 1));
    }
}
