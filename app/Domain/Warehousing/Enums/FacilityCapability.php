<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Enums;

/**
 * What a facility is allowed to do.
 *
 * Workflows ask a facility whether it has a capability; they never ask
 * for its name. A manufacturing order may only be raised for a facility
 * that can manufacture, whatever it is called.
 */
enum FacilityCapability: string
{
    case Store = 'can_store';
    case Receive = 'can_receive';
    case Qc = 'can_qc';
    case Manufacture = 'can_manufacture';
    case Pack = 'can_pack';
    case Dispatch = 'can_dispatch';
    case Return = 'can_return';

    public function label(): string
    {
        return match ($this) {
            self::Store => 'Storage',
            self::Receive => 'Receiving',
            self::Qc => 'Quality Control',
            self::Manufacture => 'Manufacturing',
            self::Pack => 'Packaging',
            self::Dispatch => 'Dispatch',
            self::Return => 'Returns',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Store => 'STORE',
            self::Receive => 'RECV',
            self::Qc => 'QC',
            self::Manufacture => 'MFG',
            self::Pack => 'PACK',
            self::Dispatch => 'DISP',
            self::Return => 'RET',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Store => 'Holds stock in one or more stores.',
            self::Receive => 'Books goods receipts against purchase requests.',
            self::Qc => 'Runs quality checks and releases from quarantine.',
            self::Manufacture => 'Runs production plans and manufacturing orders.',
            self::Pack => 'Fills and packs bulk into finished goods.',
            self::Dispatch => 'Ships stock out to customers or other facilities.',
            self::Return => 'Accepts returned goods.',
        };
    }

    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
