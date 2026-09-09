<?php

declare(strict_types=1);

namespace App\Domain\Formulation\DTOs;

/**
 * Everything a formulation workbook yielded.
 */
final class ParsedWorkbook
{
    /**
     * @param  list<ParsedFormula>  $formulas
     * @param  list<string>  $skippedSheets  Sheets with nothing usable on them.
     */
    public function __construct(
        public array $formulas = [],
        public array $skippedSheets = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'formulas' => array_map(static fn (ParsedFormula $f): array => $f->toArray(), $this->formulas),
            'skipped_sheets' => $this->skippedSheets,
        ];
    }
}
