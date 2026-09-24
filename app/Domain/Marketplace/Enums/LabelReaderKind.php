<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Enums;

/**
 * How a marketplace's label PDFs are read. Meesho and Flipkart print text
 * that can be read exactly; Amazon and Myntra labels arrive as pictures
 * and go to the AI reader.
 */
enum LabelReaderKind: string
{
    case Meesho = 'meesho';
    case Flipkart = 'flipkart';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Meesho => 'Meesho label text',
            self::Flipkart => 'Flipkart label text',
            self::Ai => 'AI reader',
        };
    }
}
