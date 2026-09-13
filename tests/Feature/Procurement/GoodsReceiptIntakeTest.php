<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Domain\Access\Enums\RoleName;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Procurement\Contracts\InvoiceReader;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Procurement\Services\InvoiceIntakeService;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeInvoiceReader;
use Tests\TestCase;

/**
 * The supplier's bill is uploaded, read, matched, and the receipt is
 * booked from it. Only the plant head keys one in by hand.
 */
class GoodsReceiptIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $rmStore;

    private RawMaterial $sles;

    private RawMaterial $glycerine;

    private Vendor $vendor;

    private User $storekeeper;

    private User $plantHead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->rmStore = Warehouse::factory()->create(['code' => 'WH-RM']);
        Warehouse::factory()->quarantine()->create(['code' => 'WH-QA']);

        $this->sles = RawMaterial::factory()->create([
            'code' => 'RM-1001', 'name' => 'Sodium Laureth Sulphate 70%', 'hsn_code' => '34021190',
            'requires_qc' => true, 'shelf_life_days' => 730,
        ]);
        $this->glycerine = RawMaterial::factory()->create(['code' => 'RM-1002', 'name' => 'Glycerine IP', 'hsn_code' => '29054500']);
        $this->vendor = Vendor::factory()->create(['name' => 'Galaxy Surfactants Ltd', 'gstin' => '27AAACG1234A1Z5']);

        $this->storekeeper = User::factory()->create();
        $this->storekeeper->assignRole(RoleName::StoreExecutive->value);

        $this->plantHead = User::factory()->create();
        $this->plantHead->assignRole(RoleName::FactoryManager->value);
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function bindReader(?array $data = null, ?string $failWith = null, bool $available = true): FakeInvoiceReader
    {
        $reader = new FakeInvoiceReader($data, $available, $failWith);
        $this->app->instance(InvoiceReader::class, $reader);

        return $reader;
    }

    /**
     * A bill as the reader would return it: one goods line and a freight line.
     *
     * @return array<string, mixed>
     */
    private function bill(): array
    {
        return [
            'vendor_name' => 'Galaxy Surfactants Limited',
            'vendor_gstin' => '27AAACG1234A1Z5',
            'vendor_address' => 'Navi Mumbai',
            'invoice_number' => 'GS/2026-27/0451',
            'invoice_date' => '11-09-2026',
            'subtotal' => '1,00,000.00',
            'tax' => '18,000.00',
            'total' => '1,18,000.00',
            'currency' => 'INR',
            'lines' => [
                [
                    'description' => 'Sodium Laureth Sulphate 70% (SLES) - drum',
                    'hsn' => '34021190',
                    'quantity' => '200',
                    'unit' => 'KGS',
                    'rate' => '118.00',
                    'amount' => '23,600.00',
                    'batch' => 'GX2609A',
                    'manufactured_at' => '05/09/2026',
                    'expiry_at' => '04/09/2028',
                ],
                [
                    'description' => 'Freight & forwarding',
                    'hsn' => '9965',
                    'quantity' => null,
                    'unit' => null,
                    'rate' => null,
                    'amount' => '1,500.00',
                    'batch' => null,
                    'manufactured_at' => null,
                    'expiry_at' => null,
                ],
            ],
            'warnings' => ['The freight line carries no quantity.'],
        ];
    }

    private function pdf(): File
    {
        $file = UploadedFile::fake()->createWithContent('GS-0451.pdf', "%PDF-1.4\n% a scanned bill\n");
        $file->mimeTypeToReport = 'application/pdf';

        return $file;
    }

    private function tokenFrom(TestResponse $response): string
    {
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertIsString($query['intake'] ?? null, 'The upload should land on the receipt form with an intake token.');

        return $query['intake'];
    }

    private function kg(): Uom
    {
        return Uom::where('code', 'KG')->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = [], array $line = []): array
    {
        return [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->rmStore->id,
            'received_at' => now()->toDateString(),
            'invoice_ref' => 'GS/2026-27/0451',
            'post_now' => false,
            'lines' => [[
                'item_id' => $this->sles->id,
                'quantity' => '999',
                'uom_id' => $this->kg()->id,
                'unit_price' => '1',
                'supplier_batch_ref' => 'TYPED',
                'expiry_at' => '2028-09-04',
                ...$line,
            ]],
            ...$overrides,
        ];
    }

    // ---- Scan -> receipt ---------------------------------------------------

    #[Test]
    public function an_uploaded_bill_is_read_matched_and_booked_as_the_receipt(): void
    {
        $reader = $this->bindReader($this->bill());

        $response = $this->actingAs($this->storekeeper)
            ->post(route('goods-receipts.intake'), ['invoice' => $this->pdf()]);

        $response->assertRedirect();
        $token = $this->tokenFrom($response);

        $this->assertCount(1, $reader->calls);
        $this->assertSame('application/pdf', $reader->calls[0]['mime']);
        $this->assertSame('GS-0451.pdf', $reader->calls[0]['filename']);
        $this->assertGreaterThan(0, $reader->calls[0]['bytes']);

        $stored = Storage::disk('local')->allFiles('goods-receipts/intake');
        $this->assertCount(1, $stored, 'The bill is kept as uploaded.');

        $this->actingAs($this->storekeeper)
            ->get(route('goods-receipts.create', ['intake' => $token]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('goods-receipts/create')
                ->where('intake.token', $token)
                ->where('intake.vendor.id', $this->vendor->id)
                ->where('intake.vendor.matched_by', 'gstin')
                ->where('intake.extraction.invoice_number', 'GS/2026-27/0451')
                ->where('intake.extraction.invoice_date', '2026-09-11')
                ->where('intake.extraction.total', '118000.00')
                ->has('intake.lines', 2)
                ->where('intake.lines.0.item_id', $this->sles->id)
                ->where('intake.lines.0.quantity', '200')
                ->where('intake.lines.0.uom_id', $this->kg()->id)
                ->where('intake.lines.0.batch', 'GX2609A')
                ->where('intake.lines.0.manufactured_at', '2026-09-05')
                ->where('intake.lines.0.expiry_at', '2028-09-04')
                ->where('intake.lines.1.item_id', null)
                ->where('intake.lines.1.quantity', null)
                ->where('reader.available', true)
                ->where('can.manual', false)
            );

        // Whatever the storekeeper types, the bill's particulars stand.
        $response = $this->actingAs($this->storekeeper)->post(
            route('goods-receipts.store'),
            $this->payload(['intake_token' => $token], ['intake_index' => 0]),
        );

        $receipt = GoodsReceipt::sole();
        $response->assertRedirect(route('goods-receipts.show', $receipt));

        $this->assertSame('scan', $receipt->entry_mode);
        $this->assertSame($stored[0], $receipt->invoice_path);
        $this->assertSame('GS-0451.pdf', $receipt->invoice_name);
        $this->assertSame('application/pdf', $receipt->invoice_mime);
        $this->assertSame('fake-reader', $receipt->extraction_model);
        $this->assertNotNull($receipt->extracted_at);
        $this->assertSame('GS/2026-27/0451', $receipt->extraction['invoice_number']);

        $this->assertCount(1, $receipt->lines, 'The freight line is not stock.');
        $line = $receipt->lines->first();
        $this->assertSame($this->sles->id, $line->item_id);
        $this->assertSame('200.000000', $line->quantity);
        $this->assertSame('118.0000', $line->unit_price);
        $this->assertSame('GX2609A', $line->supplier_batch_ref);
        $this->assertSame('2026-09-05', $line->manufactured_at?->toDateString());
        $this->assertSame('2028-09-04', $line->expiry_at?->toDateString());

        $this->assertNull(app(InvoiceIntakeService::class)->find($token), 'A booked intake is spent.');

        $this->actingAs($this->storekeeper)
            ->get(route('goods-receipts.document', $receipt))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($this->storekeeper)
            ->get(route('goods-receipts.show', $receipt))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('document.invoice_number', 'GS/2026-27/0451')
                ->where('document.model', 'fake-reader')
                ->where('document.url', route('goods-receipts.document', $receipt))
                ->has('document.warnings', 2) // the reader's own, plus ours about the freight line
            );
    }

    #[Test]
    public function a_bill_line_the_reader_could_not_match_takes_the_item_chosen_on_screen(): void
    {
        $bill = $this->bill();
        $bill['lines'][0]['description'] = 'Surfactant base, 70% paste';
        $bill['lines'][0]['hsn'] = null;
        $this->bindReader($bill);

        $token = $this->tokenFrom(
            $this->actingAs($this->storekeeper)->post(route('goods-receipts.intake'), ['invoice' => $this->pdf()]),
        );

        $this->actingAs($this->storekeeper)
            ->get(route('goods-receipts.create', ['intake' => $token]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('intake.lines.0.item_id', null));

        $this->actingAs($this->storekeeper)->post(
            route('goods-receipts.store'),
            $this->payload(['intake_token' => $token], ['item_id' => $this->glycerine->id, 'intake_index' => 0]),
        )->assertRedirect();

        $line = GoodsReceipt::sole()->lines->sole();
        $this->assertSame($this->glycerine->id, $line->item_id);
        $this->assertSame('200.000000', $line->quantity, 'The quantity is still the bill\'s.');
    }

    // ---- Who may key a receipt by hand ------------------------------------

    #[Test]
    public function a_storekeeper_cannot_key_a_receipt_in_without_a_bill(): void
    {
        $this->bindReader();

        $this->actingAs($this->storekeeper)
            ->get(route('goods-receipts.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.manual', false)->where('intake', null));

        $this->actingAs($this->storekeeper)
            ->from(route('goods-receipts.create'))
            ->post(route('goods-receipts.store'), $this->payload())
            ->assertRedirect(route('goods-receipts.create'))
            ->assertSessionHasErrors('intake_token');

        $this->assertSame(0, GoodsReceipt::count());
    }

    #[Test]
    public function the_plant_head_may_still_enter_a_receipt_by_hand(): void
    {
        $this->bindReader();

        $this->actingAs($this->plantHead)
            ->get(route('goods-receipts.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.manual', true));

        $this->actingAs($this->plantHead)
            ->post(route('goods-receipts.store'), $this->payload())
            ->assertRedirect();

        $receipt = GoodsReceipt::sole();
        $this->assertSame('manual', $receipt->entry_mode);
        $this->assertNull($receipt->invoice_path);
        $this->assertSame('999.000000', $receipt->lines->sole()->quantity);
    }

    #[Test]
    public function the_plant_head_may_correct_what_the_reader_found(): void
    {
        $this->bindReader($this->bill());

        $token = $this->tokenFrom(
            $this->actingAs($this->plantHead)->post(route('goods-receipts.intake'), ['invoice' => $this->pdf()]),
        );

        $this->actingAs($this->plantHead)
            ->post(route('goods-receipts.store'), $this->payload(['intake_token' => $token], ['quantity' => '198.5', 'intake_index' => 0]))
            ->assertRedirect();

        $receipt = GoodsReceipt::sole();
        $this->assertSame('scan', $receipt->entry_mode);
        $this->assertSame('198.500000', $receipt->lines->sole()->quantity);
    }

    // ---- Vendor not on file ------------------------------------------------

    #[Test]
    public function an_unknown_vendor_is_suggested_and_can_be_added_from_the_receipt_screen(): void
    {
        $bill = $this->bill();
        $bill['vendor_name'] = 'Fine Organics Pvt Ltd';
        $bill['vendor_gstin'] = '27AABCF9999Q1ZK';
        $this->bindReader($bill);

        $token = $this->tokenFrom(
            $this->actingAs($this->storekeeper)->post(route('goods-receipts.intake'), ['invoice' => $this->pdf()]),
        );

        $this->actingAs($this->storekeeper)
            ->get(route('goods-receipts.create', ['intake' => $token]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('intake.vendor.id', null)
                ->where('intake.vendor.matched_by', null)
                ->where('intake.vendor.suggested.name', 'Fine Organics Pvt Ltd')
                ->where('intake.vendor.suggested.gstin', '27AABCF9999Q1ZK')
            );

        $this->actingAs($this->storekeeper)
            ->postJson(route('vendors.quick'), ['name' => 'Fine Organics Pvt Ltd', 'gstin' => '27AABCF9999Q1ZK', 'supply_type' => 'raw_material'])
            ->assertOk()
            ->assertJsonPath('label', 'Fine Organics Pvt Ltd')
            ->assertJsonPath('code', 'VEN-0002');

        $this->assertDatabaseHas('vendors', ['gstin' => '27AABCF9999Q1ZK', 'is_approved' => true, 'is_active' => true]);

        // The same GSTIN cannot be added twice.
        $this->actingAs($this->storekeeper)
            ->postJson(route('vendors.quick'), ['name' => 'Fine Organics again', 'gstin' => '27AABCF9999Q1ZK'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gstin');
    }

    #[Test]
    public function a_vendor_is_matched_by_name_when_the_gstin_is_missing(): void
    {
        $bill = $this->bill();
        $bill['vendor_gstin'] = null;
        $bill['vendor_name'] = 'GALAXY SURFACTANTS LTD.';
        $this->bindReader($bill);

        $token = $this->tokenFrom(
            $this->actingAs($this->storekeeper)->post(route('goods-receipts.intake'), ['invoice' => $this->pdf()]),
        );

        $this->actingAs($this->storekeeper)
            ->get(route('goods-receipts.create', ['intake' => $token]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('intake.vendor.id', $this->vendor->id)
                ->where('intake.vendor.matched_by', 'name')
            );
    }

    // ---- Failures ----------------------------------------------------------

    #[Test]
    public function a_bill_the_reader_cannot_read_is_reported_and_not_kept(): void
    {
        $this->bindReader(failWith: 'The bill could not be read. Scan it again at a higher resolution.');

        $this->actingAs($this->storekeeper)
            ->from(route('goods-receipts.create'))
            ->post(route('goods-receipts.intake'), ['invoice' => $this->pdf()])
            ->assertRedirect(route('goods-receipts.create'))
            ->assertSessionHasErrors('invoice');

        $this->assertSame([], Storage::disk('local')->allFiles('goods-receipts/intake'));
    }

    #[Test]
    public function only_a_pdf_or_a_photo_is_accepted(): void
    {
        $reader = $this->bindReader($this->bill());

        $this->actingAs($this->storekeeper)
            ->from(route('goods-receipts.create'))
            ->post(route('goods-receipts.intake'), ['invoice' => UploadedFile::fake()->create('bill.txt', 4, 'text/plain')])
            ->assertRedirect(route('goods-receipts.create'))
            ->assertSessionHasErrors('invoice');

        $this->assertSame([], $reader->calls);
    }

    #[Test]
    public function the_screen_says_when_the_reader_is_not_configured(): void
    {
        $this->bindReader(available: false);

        $this->actingAs($this->plantHead)
            ->get(route('goods-receipts.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('reader.available', false));
    }

    #[Test]
    public function a_stale_intake_token_is_ignored(): void
    {
        $this->bindReader();

        $this->actingAs($this->plantHead)
            ->get(route('goods-receipts.create', ['intake' => '3f1c1a4e-0000-4000-8000-000000000000']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('intake', null));
    }
}
