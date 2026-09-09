<?php

declare(strict_types=1);

namespace App\Domain\Formulation\DTOs;

/**
 * How an import should treat what the parser could not decide.
 */
final readonly class ImportOptions
{
    public function __construct(
        /** Add "Purified Water, QS to 100" to a sheet that lists no filler. */
        public bool $assumeWaterQs = true,
        /** Create raw materials the master data does not have yet. */
        public bool $createMissingMaterials = true,
        /** Activate each imported version that accounts for the whole batch. */
        public bool $activate = false,
    ) {}
}
