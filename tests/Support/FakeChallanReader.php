<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Inventory\Contracts\ChallanReader;
use App\Domain\Inventory\DTOs\ChallanExtraction;
use App\Domain\Inventory\Exceptions\StockTransferException;

/**
 * Stands in for Claude in tests: says whatever the test wants the
 * consignment's paperwork to say, and remembers what it was handed.
 */
class FakeChallanReader implements ChallanReader
{
    /** @var list<array{mime: string, filename: string, bytes: int}> */
    public array $calls = [];

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(private ?array $data = null, private bool $available = true, private ?string $failWith = null) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function read(string $contents, string $mime, string $filename): ChallanExtraction
    {
        $this->calls[] = ['mime' => $mime, 'filename' => $filename, 'bytes' => strlen($contents)];

        if (! $this->available) {
            throw new StockTransferException('The document reader is not set up on this server (ANTHROPIC_API_KEY is missing). Type the inward code printed under the QR on the challan instead.');
        }

        if ($this->failWith !== null) {
            throw new StockTransferException($this->failWith);
        }

        return ChallanExtraction::fromArray($this->data ?? [], 'fake-reader');
    }
}
