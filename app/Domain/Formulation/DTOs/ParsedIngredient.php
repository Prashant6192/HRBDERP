<?php

declare(strict_types=1);

namespace App\Domain\Formulation\DTOs;

/**
 * One recipe line as read from a spreadsheet, before it is matched to a
 * material in the master data.
 */
final class ParsedIngredient
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $lineNo,
        public string $name,
        public ?string $inciName = null,
        public ?string $tradeName = null,
        public ?string $percentage = null,
        public bool $isQs = false,
        public ?string $qsNote = null,
        public ?string $grade = null,
        public ?string $purpose = null,
        public array $warnings = [],
    ) {}

    public function isAsRequired(): bool
    {
        return $this->percentage === null && ! $this->isQs;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line_no' => $this->lineNo,
            'name' => $this->name,
            'inci_name' => $this->inciName,
            'trade_name' => $this->tradeName,
            'percentage' => $this->percentage,
            'is_qs' => $this->isQs,
            'qs_note' => $this->qsNote,
            'grade' => $this->grade,
            'purpose' => $this->purpose,
            'as_required' => $this->isAsRequired(),
            'warnings' => $this->warnings,
        ];
    }
}
