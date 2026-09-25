<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Why stock moved.
 *
 * The type is descriptive, not behavioural: a line's sign says which way the
 * stock went, and the ledger service checks that the sign agrees with the
 * type, so a "receipt" with a negative line is rejected rather than posted.
 */
enum InventoryTransactionType: string
{
    case OpeningBalance = 'OPENING_BALANCE';
    case OpeningCorrection = 'OPENING_CORRECTION';
    case GrnReceipt = 'GRN_RECEIPT';
    case PurchaseReturn = 'PURCHASE_RETURN';
    case QcRelease = 'QC_RELEASE';
    case QcRejection = 'QC_REJECTION';
    case ProductionConsumption = 'PRODUCTION_CONSUMPTION';
    case ProductionReturn = 'PRODUCTION_RETURN';
    case ProductionOutput = 'PRODUCTION_OUTPUT';
    case StockTransfer = 'STOCK_TRANSFER';
    case StockAdjustmentIn = 'STOCK_ADJUSTMENT_IN';
    case StockAdjustmentOut = 'STOCK_ADJUSTMENT_OUT';
    case Damage = 'DAMAGE';
    case Expiry = 'EXPIRY';
    case Sample = 'SAMPLE';
    case SalesDispatch = 'SALES_DISPATCH';
    // A parcel packed against a marketplace label.
    case MarketplaceSale = 'MARKETPLACE_SALE';
    case MarketplaceReturn = 'MARKETPLACE_RETURN';
    case MarketplaceTransfer = 'MARKETPLACE_TRANSFER';
    case Reversal = 'REVERSAL';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening balance',
            self::OpeningCorrection => 'Opening stock corrected',
            self::GrnReceipt => 'Goods receipt',
            self::PurchaseReturn => 'Purchase return',
            self::QcRelease => 'QC release to store',
            self::QcRejection => 'QC rejection',
            self::ProductionConsumption => 'Consumed in production',
            self::ProductionReturn => 'Returned from production',
            self::ProductionOutput => 'Production output',
            self::StockTransfer => 'Stock transfer',
            self::StockAdjustmentIn => 'Adjustment (in)',
            self::StockAdjustmentOut => 'Adjustment (out)',
            self::Damage => 'Damage',
            self::Expiry => 'Expired',
            self::Sample => 'Sample',
            self::SalesDispatch => 'Dispatched',
            self::MarketplaceSale => 'Sold online (packed)',
            self::MarketplaceReturn => 'Online order returned',
            self::MarketplaceTransfer => 'Transferred to marketplace',
            self::Reversal => 'Reversal of an earlier posting',
        };
    }

    /**
     * Which direction lines of this type are allowed to go.
     * 'in' → positive only, 'out' → negative only, 'both' → transfers,
     * which net to zero; 'signed' → either way without netting, for
     * correcting opening stock before any of it has moved.
     */
    public function direction(): string
    {
        return match ($this) {
            self::OpeningBalance,
            self::GrnReceipt,
            self::ProductionReturn,
            self::ProductionOutput,
            self::StockAdjustmentIn,
            self::MarketplaceReturn => 'in',

            self::PurchaseReturn,
            self::ProductionConsumption,
            self::StockAdjustmentOut,
            self::Damage,
            self::Expiry,
            self::Sample,
            self::SalesDispatch,
            self::MarketplaceSale => 'out',

            self::QcRelease,
            self::QcRejection,
            self::StockTransfer,
            self::MarketplaceTransfer,
            self::Reversal => 'both',

            self::OpeningCorrection => 'signed',
        };
    }

    public function isTransfer(): bool
    {
        return $this->direction() === 'both';
    }
}
