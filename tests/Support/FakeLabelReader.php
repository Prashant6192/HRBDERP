<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\DTOs\LabelExtraction;
use App\Domain\Marketplace\DTOs\LabelReading;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;

/**
 * Stands in for Claude reading picture-only labels: says whatever the test
 * wants the pages to say, and remembers what it was handed.
 */
class FakeLabelReader implements AiLabelReader
{
    /** @var list<array{filename: string, marketplace: string, pages: int}> */
    public array $calls = [];

    /**
     * @param  list<array<string, mixed>>  $parcels
     * @param  list<int>  $ignoredPages
     */
    public function __construct(private array $parcels = [], private bool $available = true, private ?string $failWith = null, private array $ignoredPages = []) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function read(string $contents, string $filename, string $marketplace, int $pageCount): LabelReading
    {
        $this->calls[] = ['filename' => $filename, 'marketplace' => $marketplace, 'pages' => $pageCount];

        if ($this->failWith !== null) {
            throw new OnlineOrderException($this->failWith);
        }

        return new LabelReading(
            parcels: array_map(fn (array $p) => LabelExtraction::fromArray($p), $this->parcels),
            readWith: 'ai',
            model: 'fake-reader',
            ignoredPages: $this->ignoredPages,
        );
    }
}
