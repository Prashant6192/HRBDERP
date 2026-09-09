<?php

declare(strict_types=1);

namespace App\Domain\Formulation\DTOs;

/**
 * What an import would do, for a person to confirm before it does it.
 *
 * Built by FormulaImportService::plan() and executed by import(); the same
 * plan is shown on screen and printed by the console command.
 */
final class ImportPlan
{
    /**
     * @param  list<array<string, mixed>>  $formulas
     * @param  list<string>  $skippedSheets
     */
    public function __construct(
        public array $formulas = [],
        public array $skippedSheets = [],
    ) {}

    public function materialsToCreate(): int
    {
        $names = [];

        foreach ($this->formulas as $formula) {
            if (! in_array($formula['action'], ['create', 'new_version'], strict: true)) {
                continue;
            }

            foreach ($formula['lines'] as $line) {
                if ($line['action'] === 'create') {
                    $names[$line['key']] = true;
                }
            }
        }

        return count($names);
    }

    public function countByAction(string $action): int
    {
        return count(array_filter($this->formulas, static fn (array $f): bool => $f['action'] === $action));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'formulas' => $this->formulas,
            'skipped_sheets' => $this->skippedSheets,
            'summary' => [
                'create' => $this->countByAction('create'),
                'new_version' => $this->countByAction('new_version'),
                'skip' => $this->countByAction('skip_identical') + $this->countByAction('skip_duplicate'),
                'materials_to_create' => $this->materialsToCreate(),
            ],
        ];
    }
}
