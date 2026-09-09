<?php

declare(strict_types=1);

namespace App\Http\Requests\Formulation;

use App\Domain\Formulation\Enums\MaterialGrade;
use App\Domain\MasterData\Enums\ItemType;
use Illuminate\Validation\Rule;

/**
 * The validation every recipe submission shares, whether it creates a
 * formula or revises a draft version.
 */
trait FormulaLinesRules
{
    /**
     * @return array<string, mixed>
     */
    protected function versionRules(): array
    {
        return [
            'batch_size' => ['required', 'numeric', 'gt:0'],
            'batch_uom_id' => [
                'required', 'integer',
                Rule::exists('uoms', 'id')->where(fn ($q) => $q->where('is_active', true)->whereIn('dimension', ['mass', 'volume'])),
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
            'change_summary' => ['nullable', 'string', 'max:255'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('items', 'id')->where(fn ($q) => $q
                    ->whereIn('type', [ItemType::RawMaterial->value, ItemType::SemiFinished->value])
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'lines.*.percentage' => ['nullable', 'required_without:lines.*.is_qs', 'numeric', 'between:0,100'],
            'lines.*.is_qs' => ['sometimes', 'boolean'],
            'lines.*.qs_note' => ['nullable', 'string', 'max:64'],
            'lines.*.grade' => ['nullable', 'string', Rule::in(array_map(static fn (MaterialGrade $g): string => $g->value, MaterialGrade::cases()))],
            'lines.*.phase' => ['nullable', 'string', 'max:8'],
            'lines.*.purpose' => ['nullable', 'string', 'max:128'],
            'lines.*.inci_name' => ['nullable', 'string', 'max:255'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function versionMessages(): array
    {
        return [
            'lines.required' => 'Add at least one ingredient.',
            'lines.*.item_id.required' => 'Choose a material.',
            'lines.*.item_id.distinct' => 'This material is already on another line.',
            'lines.*.item_id.exists' => 'Choose an active raw material.',
            'lines.*.percentage.between' => 'A percentage must be between 0 and 100.',
            'batch_uom_id.exists' => 'The batch unit must be a unit of mass or volume.',
        ];
    }

    /**
     * Blank percentages arrive as empty strings from the form; treat them as
     * "not given" so the "as required" and QS cases validate cleanly.
     */
    protected function normaliseLines(): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $isQs = filter_var($line['is_qs'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $percentage = isset($line['percentage']) ? trim((string) $line['percentage']) : '';

            $lines[$index]['is_qs'] = $isQs;
            $lines[$index]['percentage'] = ($isQs || $percentage === '') ? null : $percentage;
        }

        $this->merge(['lines' => array_values($lines)]);
    }
}
