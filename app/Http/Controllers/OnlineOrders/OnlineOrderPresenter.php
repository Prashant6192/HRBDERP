<?php

declare(strict_types=1);

namespace App\Http\Controllers\OnlineOrders;

use App\Domain\Marketplace\Models\LabelBatch;
use App\Domain\Marketplace\Models\LabelFile;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Models\ShipmentLine;
use App\Support\Math\Decimal;

/**
 * How a batch, a file and a parcel look to the screens.
 */
final class OnlineOrderPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function batch(LabelBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'number' => $batch->number,
            'for_date' => $batch->for_date->toDateString(),
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'brand' => $batch->brand?->name,
            'brand_id' => $batch->brand_id,
            'marketplace' => $batch->marketplace?->name,
            'marketplace_id' => $batch->marketplace_id,
            'facility' => $batch->facility?->name,
            'store' => $batch->warehouse?->name,
            'store_code' => $batch->warehouse?->code,
            'uploaded_by' => $batch->uploader?->name,
            'created_at' => $batch->created_at?->toIso8601String(),
            'closed_at' => $batch->closed_at?->toIso8601String(),
            'closed_by' => $batch->closer?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function file(LabelFile $file): array
    {
        return [
            'id' => $file->id,
            'name' => $file->original_name,
            'pages' => $file->pages,
            'size' => $file->size,
            'read_with' => $file->read_with,
            'warnings' => $file->warnings ?? [],
            'shipments' => (int) ($file->shipments_count ?? $file->shipments()->count()),
            'uploaded_by' => $file->uploader?->name,
            'uploaded_at' => $file->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function shipment(Shipment $s, bool $withStock = true): array
    {
        return [
            'id' => $s->id,
            'batch_id' => $s->label_batch_id,
            'file_id' => $s->label_file_id,
            'pages' => $s->pages,
            'awb' => $s->awb,
            'alt_code' => $s->alt_code,
            'order_number' => $s->order_number,
            'courier' => $s->courier,
            'payment_mode' => $s->payment_mode->value,
            'payment_label' => $s->payment_mode->label(),
            'payable_amount' => $s->payable_amount,
            'invoice_number' => $s->invoice_number,
            'customer_name' => $s->customer_name,
            'customer_state' => $s->customer_state,
            'status' => $s->status->value,
            'status_label' => $s->status->label(),
            'status_tone' => $s->status->tone(),
            'stock_state' => $withStock ? $s->stock_state->value : null,
            'stock_label' => $withStock ? $s->stock_state->label() : null,
            'stock_tone' => $withStock ? $s->stock_state->tone() : null,
            'print_count' => $s->print_count,
            'printed_at' => $s->printed_at?->toIso8601String(),
            'packed_at' => $s->packed_at?->toIso8601String(),
            'packed_by' => $s->packer?->name,
            'pack_method' => $s->pack_method,
            'pack_note' => $s->pack_note,
            'handed_over_at' => $s->handed_over_at?->toIso8601String(),
            'cancel_reason' => $s->cancel_reason,
            'warnings' => $s->warnings ?? [],
            'marketplace' => $s->relationLoaded('marketplace') ? $s->marketplace?->name : null,
            'brand' => $s->relationLoaded('brand') ? $s->brand?->name : null,
            'lines' => $s->lines->map(fn (ShipmentLine $l) => [
                'id' => $l->id,
                'seller_sku' => $l->seller_sku,
                'description' => $l->description,
                'quantity' => $l->quantity,
                'item_id' => $l->item_id,
                'item' => $l->item?->name,
                'item_code' => $l->item?->code,
                'units' => $l->units === null ? null : Decimal::strip($l->units),
            ])->all(),
        ];
    }
}
