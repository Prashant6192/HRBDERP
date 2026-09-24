<?php

declare(strict_types=1);

namespace Tests\Unit\Marketplace;

use App\Domain\Marketplace\Enums\PaymentMode;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Readers\Couriers;
use App\Domain\Marketplace\Readers\FlipkartLabelParser;
use App\Domain\Marketplace\Readers\MeeshoLabelParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\LabelFixtures;

/**
 * The text readers against the shapes the real labels take — including
 * the ones where the PDF runs words together or wraps a line.
 */
class LabelParsersTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function meesho(array $overrides = []): array
    {
        return [
            'awb' => 'VL0000000000001', 'courier' => 'Valmo', 'payment' => 'cod', 'sku' => 'Hair oil 200 ml', 'qty' => 1,
            'order' => '100000000000000001', 'invoice' => 'abcde1001', 'total' => '199.00', 'name' => 'Asha Test', 'state' => 'Uttar Pradesh',
            ...$overrides,
        ];
    }

    #[Test]
    public function a_meesho_page_gives_every_particular(): void
    {
        $parcel = (new MeeshoLabelParser)->parse(LabelFixtures::meeshoPageText($this->meesho(['qty' => 3])), 7);

        $this->assertNotNull($parcel);
        $this->assertSame([7], $parcel->pages);
        $this->assertSame('VL0000000000001', $parcel->awb);
        $this->assertSame('Valmo', $parcel->courier);
        $this->assertSame(PaymentMode::Cod, $parcel->paymentMode);
        $this->assertSame('199.00', $parcel->payableAmount);
        $this->assertSame('abcde1001', $parcel->invoiceNumber);
        $this->assertSame('2026-09-24', $parcel->invoiceDate);
        $this->assertSame('100000000000000001', $parcel->orderNumber);
        $this->assertSame('Asha Test', $parcel->customerName);
        $this->assertSame('Uttar Pradesh', $parcel->customerState);
        $this->assertSame('07AAAAA0000A1Z5', $parcel->sellerGstin);
        $this->assertSame([['seller_sku' => 'Hair oil 200 ml', 'description' => 'Free Size', 'quantity' => 3]], $parcel->lines);
        $this->assertSame([], $parcel->warnings);
    }

    #[Test]
    public function meesho_run_together_courier_names_and_bare_payable_notes_are_understood(): void
    {
        // Valmo labels print "ValmoPickup01/10" and only "Check the payable
        // amount on the app", without the word COD.
        $text = str_replace(
            ["COD: Check the payable amount on the app\nValmo\nPickup", 'Prepaid'],
            ["Check the payable amount on the app\nValmoPickup01/10\nJKO-abcde", 'x'],
            LabelFixtures::meeshoPageText($this->meesho()),
        );

        $parcel = (new MeeshoLabelParser)->parse($text, 1);

        $this->assertSame('Valmo', $parcel?->courier);
        $this->assertSame(PaymentMode::Cod, $parcel?->paymentMode);
    }

    #[Test]
    public function a_wrapped_meesho_sku_is_joined_back_together(): void
    {
        $text = str_replace(
            "Hair oil 200 ml Free Size 1 NA\t",
            "Rahat Rooh Hair oil 200 ml pack\nof two Free Size 1 NA\t",
            LabelFixtures::meeshoPageText($this->meesho()),
        );

        $this->assertSame('Rahat Rooh Hair oil 200 ml pack of two', (new MeeshoLabelParser)->parse($text, 1)?->lines[0]['seller_sku']);
    }

    #[Test]
    public function a_page_that_is_not_a_meesho_label_is_not_read_as_one(): void
    {
        $this->assertNull((new MeeshoLabelParser)->parse("Manifest\nPickup summary\nTotal parcels: 21", 1));
        $this->assertNull((new MeeshoLabelParser)->parse(LabelFixtures::flipkartPageText([
            'awb' => 'SF0000000009F', 'payment' => 'PREPAID', 'sku' => 'X', 'description' => 'Y', 'qty' => 1,
            'order' => 'OD100000000000000001', 'invoice' => 'I', 'total' => '1.00', 'name' => 'N', 'state' => 'OR',
        ]), 1));
    }

    #[Test]
    public function a_flipkart_page_gives_both_barcodes_and_the_sku_table(): void
    {
        $parcel = (new FlipkartLabelParser)->parse(LabelFixtures::flipkartPageText([
            'awb' => 'SF0000000009F', 'alt' => 'FMPP0000000001', 'payment' => 'PREPAID',
            'sku' => 'Hair_Oil_200', 'description' => 'Test Hair Oil 200', 'qty' => 2,
            'order' => 'OD100000000000000001', 'invoice' => 'FAKEINV0001', 'total' => '432.00', 'name' => 'Mira Test', 'state' => 'OR',
        ]), 3);

        $this->assertNotNull($parcel);
        $this->assertSame('SF0000000009F', $parcel->awb);
        $this->assertSame('FMPP0000000001', $parcel->altCode);
        $this->assertSame('OD100000000000000001', $parcel->orderNumber);
        $this->assertSame('Ekart', $parcel->courier);
        $this->assertSame(PaymentMode::Prepaid, $parcel->paymentMode);
        $this->assertSame('432.00', $parcel->payableAmount);
        $this->assertSame('FAKEINV0001', $parcel->invoiceNumber);
        $this->assertSame('Mira Test', $parcel->customerName);
        $this->assertSame('OR', $parcel->customerState);
        $this->assertSame('05AAAAA0000A1Z5', $parcel->sellerGstin);
        $this->assertSame([['seller_sku' => 'Hair_Oil_200', 'description' => 'Test Hair Oil 200', 'quantity' => 2]], $parcel->lines);
    }

    #[Test]
    public function a_flipkart_description_ending_in_a_number_is_not_taken_for_the_quantity(): void
    {
        // "…Hair Oil 200" wraps so "200" sits alone on a line, above the
        // real quantity.
        $text = str_replace(
            "1Hair_Oil_200 | Test Hair Oil 200\n1",
            "1Hair_Oil_200 | Test Hair Oil\n200\n1",
            LabelFixtures::flipkartPageText([
                'awb' => 'FMPC0000000001', 'payment' => 'COD', 'sku' => 'Hair_Oil_200', 'description' => 'Test Hair Oil 200', 'qty' => 1,
                'order' => 'OD100000000000000002', 'invoice' => 'I2', 'total' => '216.00', 'name' => 'Ravi Test', 'state' => 'UP',
            ]),
        );
        $text = str_replace('TOTAL QTY: 1', '', $text);

        $parcel = (new FlipkartLabelParser)->parse($text, 1);

        $this->assertSame(1, $parcel?->lines[0]['quantity']);
        $this->assertSame('Test Hair Oil 200', $parcel?->lines[0]['description']);
        $this->assertNull($parcel?->altCode);
        $this->assertSame(PaymentMode::Cod, $parcel?->paymentMode);
    }

    #[Test]
    public function couriers_are_known_by_name_or_by_the_shape_of_the_awb(): void
    {
        $this->assertSame('Xpress Bees', Couriers::find("Prepaid\nXpress Bees\nPickup"));
        $this->assertSame('Ekart', Couriers::find('E-Kart Logistics'));
        $this->assertSame('Amazon Shipping', Couriers::find('Sold on: www.amazon.in ATSPL'));
        $this->assertSame('Valmo', Couriers::fromAwb('VL0000000000123'));
        $this->assertSame('Shadowfax', Couriers::fromAwb('SF0000000123FPL'));
        $this->assertNull(Couriers::fromAwb('1490000000000123'));
    }

    #[Test]
    public function the_same_sku_printed_differently_matches_the_same_listing(): void
    {
        $this->assertSame(MarketplaceListing::keyFor('Medicated oil 300 ml'), MarketplaceListing::keyFor('  MEDICATED  oil 300 ml '));
        $this->assertSame(MarketplaceListing::keyFor('RR-Ratna-300ml-P1'), MarketplaceListing::keyFor('rr - ratna - 300ml - p1'));
        $this->assertNotSame(MarketplaceListing::keyFor('Medicated_Oil_500ml_Po2'), MarketplaceListing::keyFor('Medicated_Oil_500ml-MFN'));
    }
}
