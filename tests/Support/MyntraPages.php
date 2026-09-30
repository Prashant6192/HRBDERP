<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * What a browser reads off Myntra's picture pages: the words as OCR gives
 * them (with its stray marks) and the barcodes. Every name, number and
 * address here is made up.
 */
final class MyntraPages
{
    /**
     * A courier label page.
     *
     * @return array{text: string, codes: list<array{format: string, text: string}>}
     */
    public static function label(string $awb, string $name, string $pincode, bool $cod = false, string $amount = '0.0'): array
    {
        return [
            'text' => implode("\n", [
                'DE E2E-ON-M7 '.($cod ? 'COD' : 'Prepaid (No amount'),
                "null-null Rs.{$amount}",
                "({$pincode})-(0910) a",
                '',
                "Buyer's Name And Address",
                '',
                "{$name} —",
                '',
                '14 Sample Road, Test Colony n\'.',
                '',
                "Sampleganj Testpur {$pincode} India |",
                '',
                'If undelivered, Please return to',
                '',
                'S HARBANSHRAM BHAGWANDAS',
                '',
                'Buyer Declaration Purchase made',
                '',
                "1,{$name},declare that the goods in this shipment are for personal use and not for",
                'resale. MM',
                'Myntra',
            ]),
            'codes' => [
                ['format' => 'code_128', 'text' => $awb],
                ['format' => 'data_matrix', 'text' => "0{$awb}|0000000DE_E20008"],
            ],
        ];
    }

    /**
     * A tax invoice page.
     *
     * @param  list<array{sku: string, name: string, qty: int}>  $items
     * @return array{text: string, codes: list<array{format: string, text: string}>}
     */
    public static function invoice(string $packetId, string $order, string $name, string $pincode, array $items, string $total, string $invoiceNo = 'I0000MG000000001', bool $withQr = true): array
    {
        $rows = [];

        foreach ($items as $item) {
            $rows[] = "{$item['sku']}(Medicated-500ml) - {$item['name']}, Size: 500ML (300";
            $rows[] = 'ML & ABOVE)';
            $rows[] = 'HSN: 30039011, 5.0% IGST';
            $rows[] = "{$item['qty']} Rs385.00 Rs61.00  Rs0.00 Rs308.57 Rs 15.43 Rs {$total}";
        }

        $digits = str_replace('-', '', $order);

        return [
            'text' => implode("\n", [
                'i. | | | | | Ill I',
                $packetId,
                "Invoice Number: {$invoiceNo} PacketID: {$packetId}",
                "Order Number: {$order} Invoice Date: 29 Sep 2026",
                'Nature of Transaction: Inter-State Order Date: 28 Sep 2026',
                'Place of Supply: BIHAR Nature of Supply: Goods',
                'Bill to / Ship to:',
                $name,
                'Customer Type: Unregistered',
                '14 Sample Road, Test Colony Sampleganj',
                "Testpur - {$pincode} BR, India",
                'Bill From: Ship From:',
                'GSTIN Number: 05SAACCAS8811G22Y',
                'Gross - Other Taxable ; SGST/',
                'Qty Ne Discount cp et Amount OST ygsr 16ST Cess Total Amount',
                ...$rows,
                "TOTAL Rs 385.00 Rs 61.00 Rs 0.00 Rs 308.57 Rs 15.43 Rs {$total}",
                'Authorized Signatory',
            ]),
            'codes' => $withQr ? [[
                'format' => 'qr_code',
                'text' => 'upi://pay?cu=INR&pa=test@bank&pn=Myntra Designs Pvt Ltd&url=https://myntra.com/invoicedetails/'.base64_encode("{$digits}-11400000001")
                    ."&gstIn=05AACCA8811G2ZY&am={$total}&invoiceNo={$invoiceNo}&invoiceDate=2026-09-29T09:42:34+05:30&gstBrkUp={GST:15.43|IGST:15.43}",
            ]] : [],
        ];
    }

    /**
     * The JSON the upload page sends for one file.
     *
     * @param  list<array{text: string, codes: list<array{format: string, text: string}>}>  $pages
     */
    public static function json(array $pages): string
    {
        return (string) json_encode(['pages' => array_map(
            fn (array $p, int $i) => ['page' => $i + 1, ...$p],
            $pages,
            array_keys($pages),
        )]);
    }
}
