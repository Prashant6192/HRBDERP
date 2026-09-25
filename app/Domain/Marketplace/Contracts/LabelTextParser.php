<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Contracts;

use App\Domain\Marketplace\DTOs\LabelExtraction;

/**
 * Reads one page of a marketplace's label PDF from its text layer. Exact,
 * instant and free — where the marketplace prints text.
 */
interface LabelTextParser
{
    /**
     * The parcel on this page, or null when the page is not one of this
     * marketplace's labels (or its text could not be made out).
     */
    public function parse(string $text, int $page): ?LabelExtraction;
}
