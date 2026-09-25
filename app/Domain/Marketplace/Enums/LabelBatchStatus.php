<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Enums;

/**
 * Open while the agency is still uploading the day's labels; closed when
 * they say that is all, which is when the depot is told to print.
 */
enum LabelBatchStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Uploading',
            self::Closed => 'Ready to print',
        };
    }
}
