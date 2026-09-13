<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Procurement\Contracts\InvoiceReader;
use App\Domain\Procurement\DTOs\InvoiceExtraction;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Procurement\Services\InvoiceIntakeService;
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
 * The two real bills the company shared, run through the intake as the
 * reader returns them: a Caldic proforma with our GSTIN printed as the
 * buyer's, and a Tally e-invoice from Fragrance Specialities with seven
 * batch-numbered lines. The reader itself is stood in for; everything
 * after it — normalisation, safeguards, matching, the receipt — is real.
 */
class SampleBillsTest extends TestCase
{
    use RefreshDatabase;

    private const string OUR_GSTIN = '05AACCA8811G2ZY';

    private Warehouse $rmStore;

    private User $storekeeper;

    private User $plantHead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        config(['erp.company.gstin' => self::OUR_GSTIN]);

        $this->rmStore = Warehouse::factory()->create(['code' => 'WH-RM']);
        Warehouse::factory()->quarantine()->create(['code' => 'WH-QA']);

        $this->storekeeper = User::factory()->create();
        $this->storekeeper->assignRole(RoleName::StoreExecutive->value);
        $this->plantHead = User::factory()->create();
        $this->plantHead->assignRole(RoleName::FactoryManager->value);
    }

    // ---- The bills as the reader returns them ----------------------------------

    /**
     * @return array<string, mixed>
     */
    private function caldicProforma(): array
    {
        return [
            'document_type' => 'proforma',
            'vendor_name' => 'CALDIC SPECIALTIES INDIA PRIVATE LIMITED',
            'vendor_gstin' => '27AAACC9611L1ZI',
            'buyer_gstin' => self::OUR_GSTIN,
            'vendor_address' => '3rd Floor, Zenith House, Opp. Mahalaxmi Race Course, Keshavrao Khadye Marg, Mahalaxmi, Mumbai – 400034, India',
            'vendor_phone' => '91 22 6613 8300',
            'vendor_email' => 'CaldicIN.Admin@caldic.com',
            'invoice_number' => 'SO 356105',
            'invoice_date' => '24/4/2026',
            'subtotal' => '64,750.00',
            'tax' => '11,655.00',
            'total' => '76,405.00',
            'currency' => 'INR',
            'lines' => [[
                'description' => 'CELLOSIZE POLYMER PCG-10',
                'hsn' => '39123919',
                'quantity' => '50.0000',
                'unit' => 'KG',
                'rate' => '1,295.0000',
                'amount' => '64,750.00',
                'batch' => null,
                'manufactured_at' => null,
                'expiry_at' => null,
            ]],
            'warnings' => ['Proforma invoice, valid for 7 days; freight shown as 0.00.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fragranceInvoice(): array
    {
        $line = fn (string $name, string $batch, string $rate): array => [
            'description' => $name, 'hsn' => '33029012', 'quantity' => '1.000', 'unit' => 'kg.',
            'rate' => $rate, 'amount' => $rate, 'batch' => $batch, 'manufactured_at' => null, 'expiry_at' => null,
        ];

        return [
            'document_type' => 'tax_invoice',
            'vendor_name' => 'Fragrance Specialities (A Unit of Ritisha Fragrances Pvt. Ltd.)',
            'vendor_gstin' => '09AAECR0368M2ZY',
            'buyer_gstin' => self::OUR_GSTIN,
            'vendor_address' => '141/3, KM Stone, Near Alwar Rly Bridge, Krishna Nagar, Mathura-281004',
            'vendor_phone' => '9997521352',
            'vendor_email' => 'accounts@fragrancespecialities.com',
            'invoice_number' => 'FS/25-26/2131',
            'invoice_date' => '28-Feb-26',
            'subtotal' => '12,800.00',
            'tax' => '2,304.00',
            // The rupee sign comes through as a stray glyph on this PDF.
            'total' => 'ī15,104.00',
            'currency' => 'INR',
            'lines' => [
                $line('Dazzle', 'DZ-02702/25-26', '1,700.00'),
                $line('Pistachio Kunafa B Plus', 'PKP-02702/25-26', '2,100.00'),
                $line('Pearl Shine', 'PS-01902/25-26', '1,600.00'),
                $line('Pantene Gold', 'PG-02702/25-26', '1,500.00'),
                $line('Butter Frost', 'BF-02702/25-26', '1,200.00'),
                $line('Especially U - 16005', 'EU-02201/25-26', '3,300.00'),
                $line('Tresemme', 'TR-02702/25-26', '1,400.00'),
            ],
            'warnings' => [],
        ];
    }

    private function fixture(string $name): UploadedFile
    {
        return new UploadedFile(base_path("tests/Fixtures/bills/{$name}"), $name, 'application/pdf', null, true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function bindReader(array $data): FakeInvoiceReader
    {
        $reader = new FakeInvoiceReader($data);
        $this->app->instance(InvoiceReader::class, $reader);

        return $reader;
    }

    private function tokenFrom(TestResponse $response): string
    {
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertIsString($query['intake'] ?? null);

        return $query['intake'];
    }

    private function kg(): Uom
    {
        return Uom::where('code', 'KG')->sole();
    }

    // ---- Normalisation ----------------------------------------------------------

    #[Test]
    public function the_bills_dates_numbers_and_gstins_are_normalised(): void
    {
        $caldic = InvoiceExtraction::fromArray($this->caldicProforma(), 'test');
        $this->assertSame('2026-04-24', $caldic->invoiceDate, '24/4/2026 is day first.');
        $this->assertSame('1295.0000', $caldic->lines[0]['rate']);
        $this->assertSame('50.0000', $caldic->lines[0]['quantity']);
        $this->assertSame('76405.00', $caldic->total);
        $this->assertSame('proforma', $caldic->documentType);
        $this->assertTrue($caldic->isProvisional());
        $this->assertSame(self::OUR_GSTIN, $caldic->buyerGstin);

        $fragrance = InvoiceExtraction::fromArray($this->fragranceInvoice(), 'test');
        $this->assertSame('2026-02-28', $fragrance->invoiceDate, '28-Feb-26 is Tally\'s way of writing it.');
        $this->assertSame('15104.00', $fragrance->total, 'The stray rupee glyph is dropped.');
        $this->assertCount(7, $fragrance->lines);
        $this->assertSame('DZ-02702/25-26', $fragrance->lines[0]['batch']);
        $this->assertSame('1.000', $fragrance->lines[0]['quantity']);
        $this->assertFalse($fragrance->isProvisional());
    }

    // ---- Caldic: proforma, our GSTIN printed as the buyer's ----------------------

    #[Test]
    public function the_caldic_proforma_is_matched_and_flagged_as_provisional(): void
    {
        $vendor = Vendor::factory()->create(['name' => 'Caldic Specialties India', 'gstin' => '27AAACC9611L1ZI']);
        $cellosize = RawMaterial::factory()->create(['code' => 'RM-CEL-010', 'name' => 'Cellosize Polymer PCG-10', 'hsn_code' => '39123919', 'stock_uom_id' => $this->kg()->id]);
        RawMaterial::factory()->create(['code' => 'RM-OTHER', 'name' => 'Some Other Polymer', 'hsn_code' => '39123919', 'stock_uom_id' => $this->kg()->id]);

        $reader = $this->bindReader($this->caldicProforma());

        $token = $this->tokenFrom(
            $this->actingAs($this->storekeeper)->post(route('goods-receipts.intake'), ['invoice' => $this->fixture('caldic-proforma-so-356105.pdf')]),
        );

        $this->assertSame('application/pdf', $reader->calls[0]['mime'], 'A real PDF is recognised from its bytes.');
        $this->assertGreaterThan(60000, $reader->calls[0]['bytes']);

        $this->actingAs($this->storekeeper)
            ->get(route('goods-receipts.create', ['intake' => $token]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('intake.vendor.id', $vendor->id)
                ->where('intake.vendor.matched_by', 'gstin')
                ->where('intake.extraction.invoice_number', 'SO 356105')
                ->where('intake.extraction.invoice_date', '2026-04-24')
                ->where('intake.extraction.document_type', 'proforma')
                ->where('intake.extraction.total', '76405.00')
                ->has('intake.lines', 1)
                ->where('intake.lines.0.item_id', $cellosize->id)
                ->where('intake.lines.0.quantity', '50.0000')
                ->where('intake.lines.0.rate', '1295.0000')
                ->where('intake.lines.0.uom_id', $this->kg()->id)
                ->where('intake.extraction.warnings', fn ($warnings) => collect($warnings)->contains(fn ($w) => str_contains($w, 'proforma invoice, not a tax invoice')))
            );
    }

    #[Test]
    public function our_own_gstin_is_never_taken_for_the_vendors(): void
    {
        $vendor = Vendor::factory()->create(['name' => 'Caldic Specialties India Private Limited', 'gstin' => '27AAACC9611L1ZI']);

        // A confused read: the buyer's GSTIN (ours) reported as the vendor's.
        $bill = $this->caldicProforma();
        $bill['vendor_gstin'] = self::OUR_GSTIN;
        $this->bindReader($bill);

        $token = $this->tokenFrom(
            $this->actingAs($this->storekeeper)->post(route('goods-receipts.intake'), ['invoice' => $this->fixture('caldic-proforma-so-356105.pdf')]),
        );

        $this->actingAs($this->storekeeper)
            ->get(route('goods-receipts.create', ['intake' => $token]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('intake.extraction.vendor_gstin', null)
                ->where('intake.vendor.id', $vendor->id)
                ->where('intake.vendor.matched_by', 'name')
                ->where('intake.extraction.warnings', fn ($warnings) => collect($warnings)->contains(fn ($w) => str_contains($w, 'is the buyer\'s — ours')))
            );
    }

    // ---- Fragrance Specialities: seven batch-numbered lines ----------------------

    #[Test]
    public function the_fragrance_invoice_books_seven_batches_in_the_vendors_own_batch_numbers(): void
    {
        // On file under a shorter name and without a GSTIN: matched by name.
        $vendor = Vendor::factory()->create(['name' => 'Fragrance Specialities', 'gstin' => null]);

        $items = [];

        foreach (['Dazzle', 'Pistachio Kunafa B Plus', 'Pearl Shine', 'Pantene Gold', 'Butter Frost', 'Especially U 16005', 'Tresemme'] as $i => $name) {
            $items[] = RawMaterial::factory()->create(['code' => sprintf('RM-FR-%03d', $i + 1), 'name' => "{$name} Fragrance", 'hsn_code' => '33029012', 'stock_uom_id' => $this->kg()->id, 'requires_qc' => true]);
        }

        // A distractor that shares a word but not the HSN.
        RawMaterial::factory()->create(['code' => 'RM-MICA', 'name' => 'Gold Dust Mica', 'hsn_code' => '25252020', 'stock_uom_id' => $this->kg()->id]);

        $this->bindReader($this->fragranceInvoice());

        $token = $this->tokenFrom(
            $this->actingAs($this->storekeeper)->post(route('goods-receipts.intake'), ['invoice' => $this->fixture('fragrance-specialities-fs-2131.pdf')]),
        );

        $this->actingAs($this->storekeeper)
            ->get(route('goods-receipts.create', ['intake' => $token]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('intake.vendor.id', $vendor->id)
                ->where('intake.vendor.matched_by', 'name')
                ->where('intake.extraction.invoice_number', 'FS/25-26/2131')
                ->where('intake.extraction.invoice_date', '2026-02-28')
                ->where('intake.extraction.document_type', 'tax_invoice')
                ->has('intake.lines', 7)
                ->where('intake.lines.0.item_id', $items[0]->id)
                ->where('intake.lines.3.item_id', $items[3]->id)
                ->where('intake.lines.5.item_id', $items[5]->id)
                ->where('intake.lines.6.item_id', $items[6]->id)
                ->where('intake.lines.0.uom_id', $this->kg()->id)
                ->where('intake.lines.0.batch', 'DZ-02702/25-26')
            );

        // The storekeeper books it straight in: the bill's figures stand.
        $lines = [];

        foreach ($items as $i => $item) {
            $lines[] = ['item_id' => $item->id, 'quantity' => '1', 'uom_id' => $this->kg()->id, 'intake_index' => $i];
        }

        $this->actingAs($this->storekeeper)->post(route('goods-receipts.store'), [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $this->rmStore->id,
            'received_at' => now()->toDateString(),
            'invoice_ref' => 'FS/25-26/2131',
            'intake_token' => $token,
            'post_now' => true,
            'lines' => $lines,
        ])->assertRedirect();

        $receipt = GoodsReceipt::sole();
        $this->assertSame('scan', $receipt->entry_mode);
        $this->assertCount(7, $receipt->lines);
        $this->assertSame(7, InventoryLot::query()->count());
        $this->assertSame(
            ['BF-02702/25-26', 'DZ-02702/25-26', 'EU-02201/25-26', 'PG-02702/25-26', 'PKP-02702/25-26', 'PS-01902/25-26', 'TR-02702/25-26'],
            InventoryLot::query()->orderBy('supplier_batch_ref')->pluck('supplier_batch_ref')->all(),
        );
        $this->assertSame('1700.0000', $receipt->lines->firstWhere('item_id', $items[0]->id)->unit_price);
        $this->assertNull(app(InvoiceIntakeService::class)->find($token));

        $this->actingAs($this->storekeeper)
            ->get(route('goods-receipts.document', $receipt))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
}
