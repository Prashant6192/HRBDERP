<?php

declare(strict_types=1);

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock control for the raw materials added from Rudrapur's opening-stock
 * sheet, as the owner asked: order when a material is down to a third of
 * what was counted, and call it critically low at the same point; ten days
 * from order to delivery. Plus the HSN code where the customs tariff (which
 * GST follows) is clear for the material.
 *
 * Only empty fields are filled, so a figure already typed in on the
 * material's page is never overwritten. Trade-name blends whose HSN depends
 * on what is in them are left for the purchase bill to settle.
 */
return new class extends Migration
{
    private const int LEAD_TIME_DAYS = 10;

    /**
     * HSN by item code, exactly as the sheet has the codes. Eight digits
     * where the tariff line is clear, the four- or six-digit heading where
     * only that is.
     */
    private const array HSN = [
        // Surface-active agents (3402)
        'CAPB' => '34024900', 'CMEA' => '34024200', 'R-H-40' => '34024200',
        'LG' => '34024200', 'DG' => '34024200', 'ARL' => '34024200', 'PEG-7' => '34024200',
        'SCG' => '34023900', 'GALSOFT' => '34023900', 'SLMI' => '34023900', 'SCI-85' => '34023900',
        // Alcohols and glycols
        'PG' => '29053200', 'GR' => '29054500', 'ZP' => '29053990', 'BG' => '29053990',
        'CSA' => '38237090', 'GINOL-16' => '38237010',
        // Acids, salts and esters
        'SDH' => '28151110', 'LA' => '29181100', 'CT' => '29181400', 'SGG' => '29181600',
        'SA' => '291821', 'Salisod' => '291821', 'O.M.C' => '29189990',
        'CCTG' => '2915', 'SE' => '2915',
        // Other defined compounds
        'B- ARBOUTIN' => '29389090', 'PO' => '29333990', 'Allantoin' => '29332100',
        'Tio' => '28230010', 'ZO' => '28170010', 'KLP' => '2507',
        // Vitamins
        'VTE' => '29362800', 'VITAMIN-C' => '29362700', 'VITAMIN-D' => '29362400',
        'RNT' => '29362100', 'NIA' => '2936', 'Vitamin- H' => '2936',
        // Polymers
        'SHA' => '39139090', 'XTM' => '3913', 'KY-MF-1' => '39069090', 'MEC' => '39123900',
        'DC-350' => '39100020', 'dc-200' => '39100020', 'DC-245' => '3910', 'TM-8500' => '3910', 'Emulsion' => '3910',
        // Vegetable oils and fats
        'OLO' => '1509', 'SYO' => '1507', 'LMN' => '1515', 'SHB' => '1515', 'JJO' => '1515',
        'AGO' => '1515', 'RHO' => '1515', 'BKO' => '1515', 'FXO' => '1515',
        // Essential oils
        'SWO' => '3301', 'RMO' => '3301', 'LVO' => '3301', 'TTO' => '3301', 'GNO' => '3301',
        // Plant extracts
        'NEEM' => '1302', 'TM' => '1302', 'GHRIT' => '1302', 'AMLA' => '1302', 'BRM' => '1302',
        'BSL' => '1302', 'CAL' => '1302', 'ASW' => '1302', 'GTE' => '1302', 'WBE' => '1302',
        'CICA' => '1302', 'BHR' => '1302', 'VNE' => '1302', 'SFE' => '1302', 'CME' => '1302',
        'CUE' => '1302', 'CRE' => '1302', 'MBE' => '1302', 'FGE' => '1302',
        // Fragrances (mixtures of odoriferous substances)
        'FR-TP' => '3302', 'FR-19025' => '3302', 'FR-FT' => '3302', 'FR-AP' => '3302',
        'FR-AQ50' => '3302', 'FR-DZ' => '3302', 'FR-PK' => '3302', 'FR-PG' => '3302',
        'FR-PS' => '3302', 'FR-25061' => '3302', 'FR-16005' => '3302', 'FR-TR' => '3302',
        'FR-BB' => '3302', 'UL1000538' => '3302', 'UL1001274' => '3302', 'UL1019526' => '3302',
    ];

    public function up(): void
    {
        $items = Item::query()
            ->where('type', ItemType::RawMaterial->value)
            ->where('description', 'like', 'Added from the opening stock sheet%')
            ->get();

        foreach ($items as $item) {
            $onHand = BigDecimal::of((string) (DB::table('stock_balances')->where('item_id', $item->id)->sum('on_hand') ?: '0'));
            $third = $onHand->dividedBy(3, 3, RoundingMode::HalfUp);

            // Both levels together, so the minimum can never end up above a
            // reorder level someone set by hand.
            if ($third->isPositive() && $item->reorder_level === null && $item->minimum_stock === null) {
                $item->reorder_level = $third->__toString();
                $item->minimum_stock = $third->__toString();
            }

            $item->lead_time_days ??= self::LEAD_TIME_DAYS;

            $item->hsn_code ??= self::HSN[$item->code] ?? null;

            if ($item->isDirty()) {
                $item->save();
            }
        }
    }

    public function down(): void
    {
        // Master data the owner asked for; nothing to take back.
    }
};
