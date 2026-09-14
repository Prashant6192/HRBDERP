<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Domain\Procurement\Contracts\InvoiceReader;
use App\Domain\Procurement\Exceptions\InvoiceIntakeException;
use App\Domain\Procurement\Services\BillReader;
use App\Domain\Procurement\Services\LocalInvoiceReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The built-in reader on the two real bills the company shared, read from
 * the PDFs themselves with no key and no network.
 */
class LocalInvoiceReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['erp.company.gstin' => '05AACCA8811G2ZY', 'erp.company.name' => 'Harbansram Bhagwandas Ayurvedic Sansthan', 'erp.ai.api_key' => null]);
    }

    private function bill(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/bills/{$name}"));
    }

    #[Test]
    public function a_tally_e_invoice_is_read_line_by_line_with_its_batches(): void
    {
        $x = (new LocalInvoiceReader)->read($this->bill('fragrance-specialities-fs-2131.pdf'), 'application/pdf', 'fs-2131.pdf');

        $this->assertSame('tax_invoice', $x->documentType);
        $this->assertSame('Fragrance Specialities (A Unit of Ritisha Fragrances Pvt. Ltd.)', $x->vendorName);
        $this->assertSame('09AAECR0368M2ZY', $x->vendorGstin);
        $this->assertSame('05AACCA8811G2ZY', $x->buyerGstin);
        $this->assertSame('FS/25-26/2131', $x->invoiceNumber);
        $this->assertSame('2026-02-28', $x->invoiceDate);
        $this->assertSame('accounts@fragrancespecialities.com', $x->vendorEmail);
        $this->assertSame('9997521352', $x->vendorPhone);
        $this->assertSame('12800.00', $x->subtotal);
        $this->assertSame('2304.00', $x->tax);
        $this->assertSame('15104.00', $x->total);
        $this->assertSame(LocalInvoiceReader::MODEL, $x->model);

        $this->assertCount(7, $x->lines);
        $this->assertSame(['Dazzle', 'Pistachio Kunafa B Plus', 'Pearl Shine', 'Pantene Gold', 'Butter Frost', 'Especially U - 16005', 'Tresemme'], array_column($x->lines, 'description'));
        $this->assertSame(['DZ-02702/25-26', 'PKP-02702/25-26', 'PS-01902/25-26', 'PG-02702/25-26', 'BF-02702/25-26', 'EU-02201/25-26', 'TR-02702/25-26'], array_column($x->lines, 'batch'));
        $this->assertSame(['1700.00', '2100.00', '1600.00', '1500.00', '1200.00', '3300.00', '1400.00'], array_column($x->lines, 'rate'));
        $this->assertSame(['1700.00', '2100.00', '1600.00', '1500.00', '1200.00', '3300.00', '1400.00'], array_column($x->lines, 'amount'));

        foreach ($x->lines as $line) {
            $this->assertSame('33029012', $line['hsn']);
            $this->assertSame('1.000', $line['quantity']);
            $this->assertSame('kg', $line['unit']);
        }
    }

    #[Test]
    public function a_proforma_with_a_pack_size_is_read_and_marked_provisional(): void
    {
        $x = (new LocalInvoiceReader)->read($this->bill('caldic-proforma-so-356105.pdf'), 'application/pdf', 'so-356105.pdf');

        $this->assertSame('proforma', $x->documentType);
        $this->assertTrue($x->isProvisional());
        $this->assertSame('CALDIC SPECIALTIES INDIA PRIVATE LIMITED', $x->vendorName);
        $this->assertSame('27AAACC9611L1ZI', $x->vendorGstin);
        $this->assertSame('05AACCA8811G2ZY', $x->buyerGstin);
        $this->assertSame('SO 356105', $x->invoiceNumber);
        $this->assertSame('2026-04-24', $x->invoiceDate);
        $this->assertSame('CaldicIN.Admin@caldic.com', $x->vendorEmail);
        $this->assertSame('76405.00', $x->total);

        $this->assertCount(1, $x->lines);
        $line = $x->lines[0];
        $this->assertSame('CELLOSIZE POLYMER PCG-10', $line['description']);
        $this->assertSame('39123919', $line['hsn']);
        $this->assertSame('50.0000', $line['quantity'], 'The quantity, not the 2 x 25 pack size.');
        $this->assertSame('KG', $line['unit']);
        $this->assertSame('1295.0000', $line['rate']);
        $this->assertSame('64750.00', $line['amount']);
    }

    #[Test]
    public function a_scan_or_a_photo_is_refused_plainly_when_claude_is_not_set_up(): void
    {
        $reader = app(InvoiceReader::class);
        $this->assertInstanceOf(BillReader::class, $reader);
        $this->assertTrue($reader->available());
        $this->assertFalse($reader->readsPhotos());

        try {
            $reader->read("%PDF-1.4\n%no text at all\n", 'application/pdf', 'scan.pdf');
            $this->fail('A PDF with no text was read.');
        } catch (InvoiceIntakeException $e) {
            $this->assertStringContainsString('carries no text', $e->getMessage());
        }

        try {
            $reader->read('not really an image', 'image/jpeg', 'bill.jpg');
            $this->fail('A photo was read without Claude.');
        } catch (InvoiceIntakeException $e) {
            $this->assertStringContainsString('photo', $e->getMessage());
        }

        // With a key, photos go to Claude and the screen says so.
        config(['erp.ai.api_key' => 'sk-test']);
        $this->assertTrue(app(InvoiceReader::class)->readsPhotos());
    }

    #[Test]
    public function the_built_in_reader_books_a_pdf_bill_end_to_end(): void
    {
        $x = app(InvoiceReader::class)->read($this->bill('fragrance-specialities-fs-2131.pdf'), 'application/pdf', 'fs-2131.pdf');

        $this->assertSame(LocalInvoiceReader::MODEL, $x->model);
        $this->assertCount(7, $x->lines);
    }
}
