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
    case MarketplaceTransfer = 'MARKETPLACE_TRANSFER';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening balance',
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
            self::MarketplaceTransfer => 'Transferred to marketplace',
        };
    }

    /**
     * Which direction lines of this type are allowed to go.
     * 'in' → positive only, 'out' → negative only, 'both' → transfers.
     */
    public function direction(): string
    {
        return match ($this) {
            self::OpeningBalance,
            self::GrnReceipt,
            self::ProductionReturn,
            self::ProductionOutput,
            self::StockAdjustmentIn => 'in',

            self::PurchaseReturn,
            self::ProductionConsumption,
            self::StockAdjustmentOut,
            self::Damage,
            self::Expiry,
            self::Sample,
            self::SalesDispatch => 'out',

            self::QcRelease,
            self::QcRejection,
            self::StockTransfer,
            self::MarketplaceTransfer => 'both',
        };
    }

    public function isTransfer(): bool
    {
        return $this->direction() === 'both';
    }
}
