<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Support;

use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Models\ShipmentPick;
use App\Domain\MasterData\Models\Item;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What the packer must put in a parcel, piece by piece, and the barcodes
 * that prove each piece: a Pack of 2 is its single product twice, a combo
 * is each of its products. The packing screen shows this in large letters
 * and ticks a piece off as the product's own barcode is scanned.
 */
final class PackCheck
{
    /**
     * @return list<array{item_id: int, name: string, size: string|null, full_name: string, pieces: int, codes: array<string, int>, has_barcode: bool, photo: string|null}>
     */
    public static function for(Shipment $shipment): array
    {
        $shipment->loadMissing(['picks.item.packOf']);

        /** @var array<int, array{item: Item, pieces: BigDecimal, codes: array<string, int>}> $rows */
        $rows = [];

        foreach ($shipment->picks as $pick) {
            /** @var ShipmentPick $pick */
            $item = $pick->item;

            if ($item === null) {
                continue;
            }

            $single = $item->packOf ?? $item;
            $each = $item->packOf !== null ? max(1, (int) $item->pack_quantity) : 1;

            $rows[$single->id] ??= ['item' => $single, 'pieces' => BigDecimal::zero(), 'codes' => []];
            $rows[$single->id]['pieces'] = $rows[$single->id]['pieces']->plus(BigDecimal::of($pick->units)->multipliedBy($each));

            // The single's barcode is one piece; a pack's own barcode is the whole pack.
            foreach ([[$single, 1], [$item, $each]] as [$product, $stands]) {
                foreach ([$product->barcode, $product->code] as $code) {
                    $code = strtoupper(trim((string) $code));

                    if ($code !== '') {
                        $rows[$single->id]['codes'][$code] ??= $stands;
                    }
                }
            }
        }

        return array_values(array_map(function (array $row): array {
            [$name, $size] = self::nameAndSize($row['item']->name);

            return [
                'item_id' => $row['item']->id,
                'name' => $name,
                'size' => $size,
                'full_name' => $row['item']->name,
                'pieces' => max(1, $row['pieces']->toScale(0, RoundingMode::UP)->toInt()),
                'codes' => $row['codes'],
                // Only a barcode printed on the product proves it; the ERP's own code is a fallback.
                'has_barcode' => trim((string) $row['item']->barcode) !== '',
                'photo' => ProductPhotos::forName($row['item']->name),
            ];
        }, $rows));
    }

    /**
     * "Rahat Rooh Medicated Oil 300ml" → ["Rahat Rooh Medicated Oil", "300 ml"].
     *
     * @return array{0: string, 1: string|null}
     */
    public static function nameAndSize(string $name): array
    {
        if (preg_match('/[\s(\-–,]*(\d+(?:\.\d+)?)\s*(ml|ltr|litre|liter|l|gm|g|kg)\b\)?/i', $name, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return [trim($name), null];
        }

        $unit = match (strtolower($m[2][0])) {
            'ltr', 'litre', 'liter', 'l' => 'l',
            'gm', 'g' => 'g',
            'kg' => 'kg',
            default => 'ml',
        };

        $rest = trim(substr($name, 0, $m[0][1]).' '.substr($name, $m[0][1] + strlen($m[0][0])));
        $rest = trim((string) preg_replace('/\s+/', ' ', $rest), ' -–,()');

        return [$rest !== '' ? $rest : trim($name), $m[1][0].' '.$unit];
    }
}
