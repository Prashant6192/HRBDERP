<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->whereNull('deleted_at')],
            // Material a third-party client sent for their own job.
            'owner_client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)->whereNull('deleted_at')],

            // Booked in against a material request: its lines close as stock lands.
            'material_request_id' => [
                'nullable', 'integer',
                Rule::exists('material_requests', 'id')->where(fn ($q) => $q->whereIn('status', ['open', 'partially_received'])),
            ],

            // Released stock goes here; it must be a real store, not a quarantine.
            // The closure form keeps `false` a real boolean: the string form of
            // an exists rule would flatten it to '' which Postgres rejects.
            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where(
                    fn ($query) => $query->where('is_active', true)->where('is_quarantine', false)->whereNull('deleted_at'),
                ),
            ],
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'invoice_ref' => ['nullable', 'string', 'max:64'],
            // The uploaded bill this receipt was read from, if any.
            'intake_token' => ['nullable', 'string', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'post_now' => ['sometimes', 'boolean'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.uom_id' => ['required', 'integer', Rule::exists('uoms', 'id')->where('is_active', true)],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.supplier_batch_ref' => ['nullable', 'string', 'max:128'],
            'lines.*.manufactured_at' => ['nullable', 'date', 'before_or_equal:received_at'],
            'lines.*.expiry_at' => ['nullable', 'date', 'after:received_at'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
            // Which line of the scanned bill this one was filled in from.
            'lines.*.intake_index' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * The destination store must be the kind of store the items live in:
     * raw materials in a raw material store, packaging in a packaging
     * store, finished goods in a finished goods or marketplace store.
     *
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $warehouse = Warehouse::query()->find((int) $this->input('warehouse_id'));

                if ($warehouse === null || $warehouse->type === WarehouseType::General) {
                    return;
                }

                $ids = collect($this->input('lines', []))->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
                $items = Item::query()->whereIn('id', $ids)->get(['id', 'code', 'name', 'type']);

                foreach ($items as $item) {
                    $allowed = self::storesFor($item->type);

                    if (! in_array($warehouse->type, $allowed, true)) {
                        $kinds = implode(' or ', array_map(fn (WarehouseType $t) => strtolower($t->label()), $allowed));
                        $validator->errors()->add('warehouse_id', "{$item->code} {$item->name} is a {$item->type->label()}: choose a {$kinds} store, not {$warehouse->code} ({$warehouse->type->label()}).");

                        return;
                    }
                }
            },
        ];
    }

    /**
     * @return list<WarehouseType>
     */
    public static function storesFor(ItemType $type): array
    {
        return match ($type) {
            ItemType::RawMaterial => [WarehouseType::RawMaterial, WarehouseType::General],
            // Spares and maintenance consumables live in the engineering store.
            ItemType::Consumable => [WarehouseType::RawMaterial, WarehouseType::Engineering, WarehouseType::General],
            ItemType::PackagingMaterial => [WarehouseType::Packaging, WarehouseType::General],
            ItemType::FinishedGood, ItemType::SemiFinished => [WarehouseType::FinishedGoods, WarehouseType::Marketplace, WarehouseType::General],
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_id.exists' => 'Choose an active store. Stock cannot be destined for a quarantine.',
            'material_request_id.exists' => 'That material request is no longer open.',
            'lines.required' => 'Add at least one line to the receipt.',
            'lines.*.item_id.required' => 'Choose an item.',
            'lines.*.quantity.gt' => 'Quantity must be greater than zero.',
            'lines.*.expiry_at.after' => 'Expiry must be after the receipt date.',
            'lines.*.manufactured_at.before_or_equal' => 'Manufacture date cannot be after the receipt date.',
        ];
    }
}
