<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Contracts;

use App\Domain\Marketplace\DTOs\LabelReading;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;

/**
 * Reads label pages that carry no text — Amazon and Myntra send pictures —
 * or that the text readers could not make out. The production
 * implementation asks Claude; tests bind a fake.
 */
interface AiLabelReader
{
    public function available(): bool;

    /**
     * @throws OnlineOrderException when the file cannot be read
     */
    public function read(string $contents, string $filename, string $marketplace, int $pageCount): LabelReading;
}
