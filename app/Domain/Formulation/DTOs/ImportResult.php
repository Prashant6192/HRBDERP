<?php

declare(strict_types=1);

namespace App\Domain\Formulation\DTOs;

/**
 * What an import did.
 */
final class ImportResult
{
    /**
     * @param  list<array{formula_id: int, code: string, name: string, version: int, activated: bool}>  $created
     * @param  list<array{formula_id: int, code: string, name: string, version: int, activated: bool}>  $versions
     * @param  list<array{sheet: string, name: string, reason: string}>  $skipped
     * @param  list<array{item_id: int, code: string, name: string}>  $materialsCreated
     */
    public function __construct(
        public array $created = [],
        public array $versions = [],
        public array $skipped = [],
        public array $materialsCreated = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'versions' => $this->versions,
            'skipped' => $this->skipped,
            'materials_created' => $this->materialsCreated,
        ];
    }
}
