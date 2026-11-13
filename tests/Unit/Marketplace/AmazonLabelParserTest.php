<?php

declare(strict_types=1);

namespace Tests\Unit\Marketplace;

use App\Domain\Marketplace\Enums\PaymentMode;
use App\Domain\Marketplace\Readers\AmazonLabelParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\LabelFixtures;

/**
 * Amazon's label file: a picture label (read by the browser) and the tax
 * invoice after it (text), shaped as the real files are. All names and
 * numbers are invented.
 */
class AmazonLabelParserTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function parcel(array $overrides = []): array
    {
        return [
            'awb' => '372700000001', 'order' => '402-1000001-2000001', 'invoice' => 'IN-101', 'name' => 'Asha Test',
            'state' => 'Uttar Pradesh', 'pincode' => '226001', 'payment' => 'prepaid',
            'items' => [['asin' => 'B0TEST0001', 'sku' => 'Medicated_Oil_300ml', 'title' => 'Rahat Rooh Medicated Oil 300ml | Herbal Oil for Stress', 'qty' => 1, 'unit' => '252.38']],
            ...$overrides,
        ];
    }

    private function seen(array $p): string
    {
        $seen = LabelFixtures::amazonLabelSeen($p);

        return $seen['text']."\n".implode("\n", array_map(fn (array $c) => "[barcode {$c['format']}] {$c['text']}", $seen['codes']));
    }

    #[Test]
    public function a_label_and_the_invoice_after_it_are_one_parcel(): void
    {
        $p = $this->parcel();
        $parcels = (new AmazonLabelParser)->parseFile([1 => $this->seen($p), 2 => LabelFixtures::amazonInvoiceText($p)]);

        $this->assertCount(1, $parcels);
        $parcel = $parcels[0];
        $this->assertSame([1, 2], $parcel->pages);
        $this->assertSame('372700000001', $parcel->awb);
        $this->assertSame('402-1000001-2000001', $parcel->orderNumber);
        $this->assertSame('Amazon Shipping', $parcel->courier);
        $this->assertSame(PaymentMode::Prepaid, $parcel->paymentMode);
        $this->assertSame('265.00', $parcel->payableAmount);
        $this->assertSame('IN-101', $parcel->invoiceNumber);
        $this->assertSame('2026-11-12', $parcel->invoiceDate);
        $this->assertSame('07AAAAA0000A1Z5', $parcel->sellerGstin);
        $this->assertSame('Asha Test', $parcel->customerName);
        $this->assertSame('Uttar Pradesh', $parcel->customerState);
        $this->assertSame('226001', $parcel->customerPincode);
        $this->assertSame([['seller_sku' => 'Medicated_Oil_300ml', 'description' => 'Rahat Rooh Medicated Oil 300ml | Herbal Oil for Stress', 'quantity' => 1]], $parcel->lines);
        $this->assertSame([], $parcel->warnings);
    }

    #[Test]
    public function a_cod_invoice_prints_no_mode_of_payment_and_another_courier_follows_atspl(): void
    {
        $p = $this->parcel([
            'awb' => '13300000000002', 'payment' => 'cod', 'courier_mark' => 'ATSPL_ DELHIVERY',
            'items' => [
                ['asin' => 'B0TEST0002', 'sku' => 'SUKOON OIL_200ml-MFN', 'title' => 'Sukoon Oil 200ml', 'qty' => 2, 'unit' => '247.62'],
                ['asin' => 'B0TEST0003', 'sku' => 'Medicated_Oil_500ml', 'title' => 'Medicated Oil 500ml', 'qty' => 1, 'unit' => '365.71'],
            ],
        ]);

        $parcel = (new AmazonLabelParser)->parseFile([1 => $this->seen($p), 2 => LabelFixtures::amazonInvoiceText($p)])[0];

        $this->assertStringNotContainsString('Mode of Payment', LabelFixtures::amazonInvoiceText($p));
        $this->assertSame(PaymentMode::Cod, $parcel->paymentMode);
        $this->assertSame('Delhivery', $parcel->courier);
        $this->assertSame('904.00', $parcel->payableAmount);
        $this->assertSame([
            ['seller_sku' => 'SUKOON OIL_200ml-MFN', 'description' => 'Sukoon Oil 200ml', 'quantity' => 2],
            ['seller_sku' => 'Medicated_Oil_500ml', 'description' => 'Medicated Oil 500ml', 'quantity' => 1],
        ], $parcel->lines);
    }

    #[Test]
    public function a_label_the_browser_did_not_read_is_still_paired_with_its_invoice_and_asks_for_the_awb(): void
    {
        $a = $this->parcel();
        $b = $this->parcel(['awb' => '372700000002', 'order' => '403-1000002-2000002', 'invoice' => 'IN-102', 'payment' => 'cod']);

        $parcels = (new AmazonLabelParser)->parseFile([
            1 => '', 2 => LabelFixtures::amazonInvoiceText($a),
            3 => '', 4 => LabelFixtures::amazonInvoiceText($b),
        ]);

        $this->assertSame([[1, 2], [3, 4]], array_map(fn ($p) => $p->pages, $parcels));
        $this->assertNull($parcels[0]->awb);
        $this->assertNull($parcels[0]->courier);
        $this->assertSame('403-1000002-2000002', $parcels[1]->orderNumber);
        $this->assertSame(PaymentMode::Cod, $parcels[1]->paymentMode);
        $this->assertStringContainsString('AWB on this Amazon label could not be read', $parcels[0]->warnings[0]);
    }

    #[Test]
    public function an_ocr_slip_in_the_order_id_is_tolerated_but_a_different_order_is_flagged(): void
    {
        $p = $this->parcel();
        $invoice = LabelFixtures::amazonInvoiceText($p);

        $slip = (new AmazonLabelParser)->parseFile([1 => $this->seen([...$p, 'order' => '402-1000001-2000007']), 2 => $invoice])[0];
        $this->assertSame([], $slip->warnings);
        $this->assertSame('402-1000001-2000001', $slip->orderNumber);

        $other = (new AmazonLabelParser)->parseFile([1 => $this->seen([...$p, 'order' => '408-5550123-9876543']), 2 => $invoice])[0];
        $this->assertStringContainsString('invoice after it is for order 402-1000001-2000001', $other->warnings[0]);
    }

    #[Test]
    public function a_label_with_no_invoice_after_it_asks_for_the_product(): void
    {
        $parcels = (new AmazonLabelParser)->parseFile([1 => $this->seen($this->parcel())]);

        $this->assertSame([1], $parcels[0]->pages);
        $this->assertSame('372700000001', $parcels[0]->awb);
        $this->assertSame(PaymentMode::Prepaid, $parcels[0]->paymentMode);
        $this->assertSame([], $parcels[0]->lines);
        $this->assertStringContainsString('No tax invoice followed', $parcels[0]->warnings[0]);
    }

    #[Test]
    public function only_amazon_invoices_count_as_amazon_invoices(): void
    {
        $this->assertTrue(AmazonLabelParser::isInvoice(LabelFixtures::amazonInvoiceText($this->parcel())));
        $this->assertFalse(AmazonLabelParser::isInvoice("Tax Invoice\nOrder Number: 1001\nInvoice Number: abc1001\nMeesho"));
    }
}
