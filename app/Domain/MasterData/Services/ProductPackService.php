<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Services;

use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\Product;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A pack of a product kept on the shelf and sold as one: "Rahat Rooh
 * Medicated Oil 500ml (Pack of 2)" is its own product, with its own stock
 * and batches, made of two of the single. The pack is found, or added the
 * first time it is needed, from the single it is made of.
 */
class ProductPackService
{
    /**
     * The pack of $quantity of a single product, added if it is not on file.
     */
    public function packOf(Product $single, int $quantity, int $userId): Product
    {
        if ($quantity < 2) {
            throw new InvalidArgumentException('A pack holds at least two.');
        }

        if ($single->pack_of_item_id !== null) {
            throw new InvalidArgumentException("{$single->name} is already a pack.");
        }

        return DB::transaction(function () use ($single, $quantity, $userId): Product {
            $existing = Product::query()
                ->where('pack_of_item_id', $single->id)
                ->where('pack_quantity', $quantity)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return Product::create([
                'code' => $this->freeCode("{$single->code}-P{$quantity}"),
                'name' => mb_substr("{$single->name} (Pack of {$quantity})", 0, 255),
                'category_id' => $single->category_id,
                'description' => "{$quantity} × {$single->name}, kept and sold as one pack.",
                'stock_uom_id' => $single->stock_uom_id,
                'hsn_code' => $single->hsn_code,
                'gst_rate' => $single->gst_rate,
                'brand' => $single->brand,
                'client_id' => $single->client_id,
                'net_content' => $single->net_content,
                'net_content_uom_id' => $single->net_content_uom_id,
                'is_batch_tracked' => $single->is_batch_tracked,
                'requires_qc' => false,
                'shelf_life_days' => $single->shelf_life_days,
                'pack_of_item_id' => $single->id,
                'pack_quantity' => $quantity,
                'is_active' => true,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        });
    }

    /**
     * Codes are unique even among deleted items: step past any in use.
     */
    private function freeCode(string $wanted): string
    {
        $wanted = mb_substr($wanted, 0, 60);
        $code = $wanted;
        $n = 2;

        while (Item::withTrashed()->where('code', $code)->exists()) {
            $code = "{$wanted}-{$n}";
            $n++;
        }

        return $code;
    }
}
