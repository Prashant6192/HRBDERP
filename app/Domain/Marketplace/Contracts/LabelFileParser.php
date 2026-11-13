<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Contracts;

use App\Domain\Marketplace\DTOs\LabelExtraction;

/**
 * A marketplace whose parcel spans several pages of the file — Amazon
 * prints the courier label, then the tax invoice on the page after it —
 * is read file-wide: the parser sees every page and says which belong
 * together.
 */
interface LabelFileParser
{
    /**
     * Every parcel in the file, each naming its pages. Pages it could not
     * place are left out, for the AI reader or a person.
     *
     * @param  array<int, string>  $texts  page number => the page's text (or what the browser read on a picture page)
     * @return list<LabelExtraction>
     */
    public function parseFile(array $texts): array;
}
