<?php

declare(strict_types=1);

namespace Tests\Unit\Marketplace;

use App\Domain\Marketplace\DTOs\BrowserPages;
use App\Domain\Marketplace\Enums\PaymentMode;
use App\Domain\Marketplace\Readers\MyntraLabelParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\MyntraPages;

/**
 * Myntra's picture pages as the browser reads them — OCR words with their
 * stray marks, and the barcodes — made into label and invoice halves.
 * Every name and number is made up.
 */
class MyntraLabelParserTest extends TestCase
{
    /**
     * @param  array{text: string, codes: list<array{format: string, text: string}>}  $page
     */
    private function read(array $page): string
    {
        return BrowserPages::fromJson(MyntraPages::json([$page]))[1] ?? $this->fail('The page was dropped.');
    }

    #[Test]
    public function a_courier_label_gives_the_awb_the_buyer_and_the_pin_code(): void
    {
        $parcel = (new MyntraLabelParser)->parse($this->read(MyntraPages::label('219100000000011', 'Ravi Verma', '845401')), 3);

        $this->assertNotNull($parcel);
        $this->assertSame([3], $parcel->pages);
        $this->assertSame('219100000000011', $parcel->awb);
        $this->assertSame('Ravi Verma', $parcel->customerName, 'The stray dash OCR reads beside the name is dropped.');
        $this->assertSame('845401', $parcel->customerPincode);
        $this->assertSame(PaymentMode::Prepaid, $parcel->paymentMode);
        $this->assertSame([], $parcel->lines);
        $this->assertNull($parcel->orderNumber);
    }

    #[Test]
    public function a_cod_label_carries_the_amount_to_collect(): void
    {
        $parcel = (new MyntraLabelParser)->parse($this->read(MyntraPages::label('219100000000028', 'Anita Rao', '560001', cod: true, amount: '649.0')), 1);

        $this->assertSame(PaymentMode::Cod, $parcel?->paymentMode);
        $this->assertSame('649.00', $parcel->payableAmount);
    }

    #[Test]
    public function without_its_barcode_the_label_takes_the_awb_from_the_square_code(): void
    {
        $page = MyntraPages::label('219100000000011', 'Ravi Verma', '845401');
        $page['codes'] = [$page['codes'][1]];

        $this->assertSame('219100000000011', (new MyntraLabelParser)->parse($this->read($page), 1)?->awb);
    }

    #[Test]
    public function a_tax_invoice_gives_the_order_the_packet_and_the_product(): void
    {
        $parcel = (new MyntraLabelParser)->parse($this->read(MyntraPages::invoice(
            '1000100000001',
            '1000001-2000002-3000003',
            'Ravi Verma',
            '845401',
            [['sku' => 'RTTHHROL100835002', 'name' => 'RAHAT ROOH Medicated Hair Oil - 500 ml', 'qty' => 2]],
            '648.00',
        )), 1);

        $this->assertNotNull($parcel);
        $this->assertNull($parcel->awb);
        $this->assertSame('1000100000001', $parcel->altCode);
        $this->assertSame('1000001-2000002-3000003', $parcel->orderNumber);
        $this->assertSame('I0000MG000000001', $parcel->invoiceNumber);
        $this->assertSame('2026-09-29', $parcel->invoiceDate);
        $this->assertSame('648.00', $parcel->payableAmount);
        $this->assertSame('05AACCA8811G2ZY', $parcel->sellerGstin, 'Taken from the QR code, not the misread print.');
        $this->assertSame('Ravi Verma', $parcel->customerName);
        $this->assertSame('845401', $parcel->customerPincode);
        $this->assertSame('BIHAR', $parcel->customerState);
        $this->assertCount(1, $parcel->lines);
        $this->assertSame('RTTHHROL100835002', $parcel->lines[0]['seller_sku']);
        $this->assertSame(2, $parcel->lines[0]['quantity']);
        $this->assertStringStartsWith('RAHAT ROOH Medicated Hair Oil', (string) $parcel->lines[0]['description']);
        $this->assertSame([], $parcel->warnings);
    }

    #[Test]
    public function without_the_qr_code_the_invoice_is_read_from_its_print(): void
    {
        $parcel = (new MyntraLabelParser)->parse($this->read(MyntraPages::invoice(
            '1000100000001',
            '1000001-2000002-3000003',
            'Ravi Verma',
            '845401',
            [['sku' => 'RTTHHROL100835002', 'name' => 'Medicated Hair Oil', 'qty' => 1]],
            '324.00',
            withQr: false,
        )), 1);

        $this->assertSame('1000001-2000002-3000003', $parcel?->orderNumber);
        $this->assertSame('I0000MG000000001', $parcel->invoiceNumber);
        $this->assertSame('2026-09-29', $parcel->invoiceDate);
        $this->assertSame('324.00', $parcel->payableAmount);
    }

    #[Test]
    public function an_invoice_with_two_products_lists_both(): void
    {
        $parcel = (new MyntraLabelParser)->parse($this->read(MyntraPages::invoice(
            '1000100000002',
            '1000001-2000002-3000004',
            'Anita Rao',
            '560001',
            [
                ['sku' => 'RTTHHROL100835002', 'name' => 'Medicated Hair Oil', 'qty' => 1],
                ['sku' => 'RTSHAMPO200100001', 'name' => 'Herbal Shampoo', 'qty' => 3],
            ],
            '999.00',
        )), 1);

        $this->assertSame(
            [['RTTHHROL100835002', 1], ['RTSHAMPO200100001', 3]],
            array_map(fn (array $l) => [$l['seller_sku'], $l['quantity']], $parcel?->lines ?? []),
        );
    }

    #[Test]
    public function an_invoice_whose_product_could_not_be_read_says_so(): void
    {
        $parcel = (new MyntraLabelParser)->parse($this->read(MyntraPages::invoice('1000100000003', '1000001-2000002-3000005', 'Ravi Verma', '845401', [], '324.00')), 1);

        $this->assertSame([], $parcel?->lines);
        $this->assertStringContainsString('could not be read', $parcel->warnings[0] ?? '');
    }

    #[Test]
    public function a_page_that_is_neither_is_left_alone(): void
    {
        $this->assertNull((new MyntraLabelParser)->parse("Pick list\nRTTHHROL100835002 x 4", 1));
    }

    #[Test]
    public function the_browser_reading_is_kept_to_plain_text(): void
    {
        $pages = BrowserPages::fromJson((string) json_encode(['pages' => [
            ['page' => 1, 'text' => 'Tax Invoice', 'codes' => [['format' => 'qr_code', 'text' => "line\nbreak"], ['format' => 'bad format!', 'text' => 'x']]],
            ['page' => 0, 'text' => 'no such page'],
            ['page' => 2, 'text' => '   ', 'codes' => []],
            'not a page',
        ]]));

        $this->assertSame([1 => "Tax Invoice\n[barcode qr_code] line break"], $pages);
        $this->assertSame([], BrowserPages::fromJson('not json'));
        $this->assertSame([], BrowserPages::fromJson(null));
    }
}
