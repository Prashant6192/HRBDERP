<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Contracts;

use App\Domain\Marketplace\DTOs\LabelExtraction;

/**
 * A label text parser whose pages may carry several labels side by side
 * (Shiprocket prints two to an A4 page). Each label it finds says which
 * slot of the page it sits in.
 */
interface LabelSheetParser extends LabelTextParser
{
    /**
     * Every parcel on this page, in the order they sit on it.
     *
     * @return list<LabelExtraction>
     */
    public function parsePage(string $text, int $page): array;
}
