<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\DTOs;

/**
 * Everything read from one label file: its parcels, the pages that were
 * not labels at all (a pick list, a summary), and anything the reader
 * wants a person to know.
 */
final class LabelReading
{
    /**
     * @param  list<LabelExtraction>  $parcels
     * @param  list<int>  $ignoredPages
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $parcels,
        public readonly string $readWith,
        public readonly ?string $model = null,
        public readonly array $ignoredPages = [],
        public readonly array $warnings = [],
        public readonly int $pageCount = 0,
    ) {}
}
