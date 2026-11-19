<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cleanse Ayurveda is the company's own brand, sold as "Cleanse". Its
 * Hydrating Facewash must be a product of the company's own — on file,
 * active, made for no outside client — so the depot can scan it in. An
 * existing record (even one switched off, deleted or filed under a client)
 * is put right rather than duplicated.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $existing = DB::table('items')
            ->where('type', 'finished_good')
            ->whereNull('pack_of_item_id')
            ->where('name', 'ilike', '%cleanse%')
            ->where('name', 'ilike', '%hydrat%')
            ->where(fn ($q) => $q->where('name', 'ilike', '%face%wash%')->orWhere('name', 'ilike', '%facewash%'))
            ->orderByRaw('deleted_at IS NOT NULL')
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            DB::table('items')->where('id', $existing->id)->update([
                'is_active' => true,
                'deleted_at' => null,
                'client_id' => null,
                'brand' => $existing->brand ?: 'Cleanse Ayurveda',
                'updated_at' => $now,
            ]);

            return;
        }

        $pieces = DB::table('uoms')->where('code', 'PCS')->value('id');

        if ($pieces === null) {
            return;
        }

        $code = 'CA-HYD-FW';

        for ($n = 2; DB::table('items')->where('code', $code)->exists(); $n++) {
            $code = "CA-HYD-FW-{$n}";
        }

        DB::table('items')->insert([
            'code' => $code,
            'name' => 'Cleanse Hydrating Facewash',
            'type' => 'finished_good',
            'stock_uom_id' => $pieces,
            'brand' => 'Cleanse Ayurveda',
            'is_batch_tracked' => true,
            'requires_qc' => false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Nothing to undo: the product may carry stock by then.
    }
};
