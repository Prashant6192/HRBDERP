<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * The life of an inter-facility transfer.
 *
 * Stock is reserved at the source on approval, leaves the source store for
 * the in-transit position on dispatch, and only reaches the destination
 * store when the receiving facility books it in. Nothing shows at the
 * destination before that.
 */
enum StockTransferStatus: string
{
    case Draft = 'draft';
    case Requested = 'requested';
    case Approved = 'approved';
    case Packed = 'packed';
    case Dispatched = 'dispatched';
    case InTransit = 'in_transit';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Discrepancy = 'discrepancy';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Packed => 'Packed',
            self::Dispatched => 'Dispatched',
            self::InTransit => 'In transit',
            self::PartiallyReceived => 'Partially received',
            self::Received => 'Received',
            self::Discrepancy => 'Received with discrepancy',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Requested => 'info',
            self::Approved, self::Packed => 'warning',
            self::Dispatched, self::InTransit => 'info',
            self::PartiallyReceived => 'warning',
            self::Received => 'success',
            self::Discrepancy => 'danger',
            self::Rejected, self::Cancelled => 'neutral',
        };
    }

    /**
     * Stock is held at the source (reserved) while in one of these.
     */
    public function holdsReservation(): bool
    {
        return in_array($this, [self::Approved, self::Packed], strict: true);
    }

    /**
     * Stock has left the source and sits in the transit position.
     */
    public function isOnTheRoad(): bool
    {
        return in_array($this, [self::Dispatched, self::InTransit, self::PartiallyReceived], strict: true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Received, self::Discrepancy, self::Rejected, self::Cancelled], strict: true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Draft, self::Requested, self::Approved, self::Packed], strict: true);
    }

    public function canBeReceived(): bool
    {
        return $this->isOnTheRoad();
    }
}
