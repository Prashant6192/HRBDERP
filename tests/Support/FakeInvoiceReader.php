<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Procurement\Contracts\InvoiceReader;
use App\Domain\Procurement\DTOs\InvoiceExtraction;
use App\Domain\Procurement\Exceptions\InvoiceIntakeException;

/**
 * Stands in for Claude in tests: returns whatever the test says the bill
 * contains, and remembers what it was handed.
 */
class FakeInvoiceReader implements InvoiceReader
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

    public function read(string $contents, string $mime, string $filename): InvoiceExtraction
    {
        $this->calls[] = ['mime' => $mime, 'filename' => $filename, 'bytes' => strlen($contents)];

        if ($this->failWith !== null) {
            throw new InvoiceIntakeException($this->failWith);
        }

        return InvoiceExtraction::fromArray($this->data ?? [], 'fake-reader');
    }
}
