<?php

declare(strict_types=1);

namespace Tests\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;

/**
 * Label PDFs laid out the way the marketplaces lay them out, with invented
 * people, places and numbers. The real samples carry customers' names and
 * addresses and are never committed.
 */
final class LabelFixtures
{
    /**
     * A Meesho "Sub Order Labels" file, one page per parcel.
     *
     * @param  list<array{awb: string, courier: string, payment: 'cod'|'prepaid', sku: string, qty: int, order: string, invoice: string, total: string, name: string, state: string, gstin?: string}>  $parcels
     */
    public static function meesho(array $parcels, string $name = 'Sub_Order_Labels.pdf'): UploadedFile
    {
        $pages = array_map(fn (array $p) => self::meeshoPage($p), $parcels);

        return self::pdf($pages, $name);
    }

    /**
     * A Flipkart label-and-invoice file, one page per parcel.
     *
     * @param  list<array{awb: string, alt?: string|null, payment: 'COD'|'PREPAID', sku: string, description: string, qty: int, order: string, invoice: string, total: string, name: string, state: string, gstin?: string}>  $parcels
     */
    public static function flipkart(array $parcels, string $name = 'invoice_labels.pdf'): UploadedFile
    {
        $pages = array_map(fn (array $p) => self::flipkartPage($p), $parcels);

        return self::pdf($pages, $name);
    }

    /**
     * Shiprocket's labels for website orders, laid out as Shiprocket prints
     * them: two labels side by side to a page.
     *
     * @param  list<array{awb: string, courier: string, payment: 'cod'|'prepaid', order: string, total: string, name: string, state: string, pincode: string, items: list<array{sku: string, item: string, qty: int, price: string}>}>  $parcels
     */
    public static function shiprocket(array $parcels, string $name = 'shiprocket_labels.pdf'): UploadedFile
    {
        $pages = array_map(
            fn (array $pair) => implode('', array_map(fn (array $p) => self::lines(self::shiprocketLabelText($p)), $pair)),
            array_chunk($parcels, 2),
        );

        return self::pdf($pages, $name);
    }

    /**
     * One Shiprocket label's text as the PDF gives it. A SKU with a pack
     * size runs past its column and ends on a later line, as Shiprocket's
     * does ("RR-MED-500 (500ML-2P" … "acK)").
     *
     * @param  array<string, mixed>  $p
     */
    public static function shiprocketLabelText(array $p): string
    {
        $rows = [];
        $qty = 0;

        foreach ($p['items'] as $item) {
            $qty += $item['qty'];

            if (preg_match('/^(.+\(\d+ML-2P)(acK\))$/', $item['sku'], $m) === 1) {
                $rows[] = $m[1];
                $rows[] = "{$item['item']}{$item['qty']} Rs.{$item['price']}";
                $rows[] = $m[2];
            } else {
                $rows[] = "{$item['sku']} {$item['item']}{$item['qty']} Rs.{$item['price']}";
            }
        }

        return implode("\n", [
            "DELIVER To:\tShipped By (If undelivered, return to) :",
            $p['name'],
            '14 Sample Road, Test Colony',
            'Sampleganj',
            "{$p['state']}, {$p['pincode']}, India",
            'MOBILE NO. : 9000000001',
            'Test Seller',
            'Plot 1, Sample Market, New Delhi, Pincode - 110096',
            '110096, India,Mobile No.: 9000000002',
            "ORDER # : {$p['order']}",
            'ROUTING CODE : W20',
            'WEIGHT : 1.00 kg',
            'DIMENSIONS : 10* 10*10(cm)',
            $p['payment'] === 'cod' ? 'CASH ON DELIVERY' : 'PREPAID',
            $p['payment'] === 'cod' ? "COLLECT COD - Rs.{$p['total']}" : 'DO NOT COLLECT CASH',
            "COURIER : {$p['courier']}",
            "AWB # : {$p['awb']}",
            "SKU\tITEM\tQTY PRICE",
            ...$rows,
            'SHIPPING CHARGES Rs.99.00',
            "TOTAL {$qty} Rs.{$p['total']}.00",
            'Invoice No. : Retail0'.$p['order'].' | Invoice Date : 2026-10-27 10:45:59',
            'Gstin No : 07AAAAA0000A1Z5',
            "1. Visit official website of {$p['courier']} to view the Conditions of Carriage.",
            'THIS IS AN AUTO-GENERATED LABEL AND DOES NOT NEED SIGNATURE.',
        ]);
    }

    /**
     * A file of pictures only, as Amazon and Myntra send: pages with no text.
     */
    public static function imageOnly(int $pages, string $name = 'amazon_labels.pdf', int $variant = 0): UploadedFile
    {
        // The variant changes the picture, so two picture-only files differ.
        $size = 100 + $variant;

        return self::pdf(array_fill(0, $pages, "<div style=\"width:{$size}px;height:100px;background:#000\"></div>"), $name);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    public static function meeshoPageText(array $p): string
    {
        $pay = $p['payment'] === 'prepaid' ? 'Prepaid: Do not collect cash' : 'COD: Check the payable amount on the app';
        $gstin = $p['gstin'] ?? '07AAAAA0000A1Z5';
        $order = $p['order'];

        return implode("\n", [
            'Customer Address',
            $p['name'],
            '12 Test Lane, Sample Nagar',
            "Sampletown, {$p['state']}, 110001",
            'If undelivered, return to:',
            'Test Depot, Plot 1, Paper Market',
            'Delhi 110096',
            $pay,
            $p['courier'],
            'Pickup',
            'Destination Code',
            'XYZ_1',
            'Return Code',
            '110096,000000',
            $p['awb'],
            'Product Details',
            "SKU\tSize Qty Color Order No.",
            "{$p['sku']} Free Size {$p['qty']} NA\t{$order}_1",
            "TAX INVOICE\tOriginal For Recipient",
            'BILL TO / SHIP TO',
            "{$p['name']} - 12 Test Lane, Sample Nagar, Sampletown, 110001, Place of Supply: {$p['state']}",
            'Sold by : TEST SELLER PRIVATE LIMITED',
            "GSTIN - {$gstin}",
            'Purchase Order No.',
            $order,
            'Invoice No.',
            $p['invoice'],
            'Order Date',
            '20.09.2026',
            'Invoice Date',
            '24.09.2026',
            "Total\tRs.10.00\tRs.{$p['total']}",
        ]);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    public static function flipkartPageText(array $p): string
    {
        $gstin = $p['gstin'] ?? '05AAAAA0000A1Z5';

        return implode("\n", array_filter([
            'B1',
            'E-Kart Logistics',
            "{$p['order']} {$p['payment']}",
            "Sold By:TEST SELLER PRIVATE LIMITED, Rudrapur GSTIN: {$gstin}",
            "SKU ID | Description\tQTY",
            "1{$p['sku']} | {$p['description']}",
            (string) $p['qty'],
            $p['alt'] ?? $p['awb'],
            'Tax InvoiceOrder Id:',
            $p['order'],
            'Invoice No:',
            $p['invoice'],
            'Invoice Date: 24-09-2026, 01:29',
            "GSTIN: {$gstin}",
            "AWB No. {$p['awb']}",
            'Shipping/Customer address:',
            "Name: {$p['name']},",
            "Sampletown - 700001, IN-{$p['state']}",
            "TOTAL QTY: {$p['qty']}",
            "TOTAL PRICE: {$p['total']}",
        ]));
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private static function meeshoPage(array $p): string
    {
        return self::lines(self::meeshoPageText($p));
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private static function flipkartPage(array $p): string
    {
        return self::lines(self::flipkartPageText($p));
    }

    private static function lines(string $text): string
    {
        return implode('', array_map(
            fn (string $l) => '<div>'.e(str_replace("\t", '    ', $l)).'</div>',
            explode("\n", $text),
        ));
    }

    /**
     * @param  list<string>  $pages
     */
    private static function pdf(array $pages, string $name): UploadedFile
    {
        $html = '<html><body style="font-family: DejaVu Sans; font-size: 10px">'
            .implode('<div style="page-break-after: always"></div>', $pages)
            .'</body></html>';

        $path = tempnam(sys_get_temp_dir(), 'labels').'.pdf';
        file_put_contents($path, Pdf::loadHTML($html)->setPaper('a4')->output());

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
