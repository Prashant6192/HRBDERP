<?php

declare(strict_types=1);

namespace App\Support\Scanning;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;

/**
 * QR codes as data URIs, for stickers, labels and screens.
 */
final class Qr
{
    public static function dataUri(string $contents, int $size = 220): string
    {
        $png = (new Writer(new GDLibRenderer($size, 1)))->writeString($contents);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
