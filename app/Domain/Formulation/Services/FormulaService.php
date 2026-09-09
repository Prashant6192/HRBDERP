<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Services;

use App\Domain\Formulation\Enums\FormulaAccessAction;
use App\Domain\Formulation\Enums\FormulaStatus;
use App\Domain\Formulation\Enums\FormulaVersionStatus;
use App\Domain\Formulation\Exceptions\FormulaStateException;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Models\FormulaIngredient;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Inventory\Services\SequenceService;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * The lifecycle of a formula and its versions.
 *
 * Rules that hold no matter which screen or import got here:
 *  - a recipe is only ever edited while it is a draft;
 *  - a formula has at most one draft open at a time;
 *  - activating a draft supersedes the previously active version, so there
 *    is exactly one active recipe (the database enforces this too);
 *  - fixed percentages never exceed 100 and at most one line is the filler.
 */
class FormulaService
{
    /** @var list<ItemType> */
    private const array INGREDIENT_TYPES = [ItemType::RawMaterial, ItemType::SemiFinished];

    public function __construct(
        private readonly SequenceService $sequences,
        private readonly FormulaSecurityService $security,
    ) {}

    /**
     * Create a formula with its first draft version.
     *
     * @param  array<string, mixed>  $attributes  name, product_id, description, batch_size, batch_uom_id, notes, source, source_reference
     * @param  list<array<string, mixed>>  $lines
     */
    public function create(array $attributes, array $lines, ?int $userId): Formula
    {
        return DB::transaction(function () use ($attributes, $lines, $userId): Formula {
            $formula = Formula::create([
                'code' => $this->nextCode(),
                'name' => trim((string) $attributes['name']),
                'product_id' => $attributes['product_id'] ?? null,
                'status' => FormulaStatus::Draft,
                'description' => $attributes['description'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->createVersion($formula, $attributes, $lines, $userId, versionNumber: 1);

            return $formula->refresh();
        });
    }

    /**
     * Change what the formula is called or which product it belongs to.
     * The recipe is untouched; that goes through a version.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDetails(Formula $formula, array $attributes, ?int $userId): Formula
    {
        $formula->fill([
            'name' => trim((string) ($attributes['name'] ?? $formula->name)),
            'product_id' => array_key_exists('product_id', $attributes) ? $attributes['product_id'] : $formula->product_id,
            'description' => array_key_exists('description', $attributes) ? $attributes['description'] : $formula->description,
            'updated_by' => $userId,
        ])->save();

        return $formula;
    }

    /**
     * Open a new draft, starting from the active (or latest) recipe.
     */
    public function newVersion(Formula $formula, ?int $userId, ?FormulaVersion $from = null, ?string $changeSummary = null): FormulaVersion
    {
        return DB::transaction(function () use ($formula, $userId, $from, $changeSummary): FormulaVersion {
            $formula = Formula::query()->lockForUpdate()->findOrFail($formula->getKey());

            if ($formula->isArchived()) {
                throw new FormulaStateException('An archived formula cannot be revised. Restore it first.');
            }

            if ($formula->draftVersion() !== null) {
                throw new FormulaStateException('This formula already has a draft open. Edit that draft, or discard it first.');
            }

            $from ??= $formula->activeVersion ?? $formula->versions()->first();

            if ($from === null) {
                throw new FormulaStateException('There is no version to start from.');
            }

            $from->loadMissing('ingredients');

            $lines = $from->ingredients->map(static fn (FormulaIngredient $line): array => [
                'item_id' => $line->item_id,
                'inci_name' => $line->inci_name,
                'percentage' => $line->percentage,
                'is_qs' => $line->is_qs,
                'qs_note' => $line->qs_note,
                'grade' => $line->grade,
                'phase' => $line->phase,
                'purpose' => $line->purpose,
                'notes' => $line->notes,
            ])->all();

            $next = ((int) $formula->versions()->max('version_number')) + 1;

            return $this->createVersion($formula, [
                'batch_size' => $from->batch_size,
                'batch_uom_id' => $from->batch_uom_id,
                'notes' => $from->notes,
                'change_summary' => $changeSummary,
            ], $lines, $userId, $next);
        });
    }

    /**
     * Replace a draft's recipe and batch basis.
     *
     * @param  array<string, mixed>  $attributes  batch_size, batch_uom_id, notes, change_summary
     * @param  list<array<string, mixed>>  $lines
     */
    public function updateVersion(FormulaVersion $version, array $attributes, array $lines, ?int $userId): FormulaVersion
    {
        return DB::transaction(function () use ($version, $attributes, $lines, $userId): FormulaVersion {
            $version = FormulaVersion::query()->lockForUpdate()->findOrFail($version->getKey());

            if (! $version->isEditable()) {
                throw new FormulaStateException("Version {$version->version_number} is {$version->status->label()} and cannot be edited. Open a new version instead.");
            }

            $version->fill([
                'batch_size' => $attributes['batch_size'] ?? $version->batch_size,
                'batch_uom_id' => $attributes['batch_uom_id'] ?? $version->batch_uom_id,
                'notes' => array_key_exists('notes', $attributes) ? $attributes['notes'] : $version->notes,
                'change_summary' => array_key_exists('change_summary', $attributes) ? $attributes['change_summary'] : $version->change_summary,
            ]);

            $this->writeLines($version, $lines);
            $version->save();

            $version->formula->forceFill(['updated_by' => $userId])->saveQuietly();

            if ($userId !== null && ($user = User::find($userId)) !== null) {
                $this->security->record($user, FormulaAccessAction::Edited, $version->formula, $version);
            }

            return $version->refresh();
        });
    }

    /**
     * Make a draft the recipe production uses.
     */
    public function activate(FormulaVersion $version, int $userId): FormulaVersion
    {
        return DB::transaction(function () use ($version, $userId): FormulaVersion {
            $version = FormulaVersion::query()->lockForUpdate()->findOrFail($version->getKey());
            $formula = Formula::query()->lockForUpdate()->findOrFail($version->formula_id);

            if ($version->status !== FormulaVersionStatus::Draft) {
                throw new FormulaStateException("Only a draft can be activated; version {$version->version_number} is {$version->status->label()}.");
            }

            $version->load('ingredients');

            if ($version->ingredients->isEmpty()) {
                throw new FormulaStateException('A recipe with no ingredients cannot be activated.');
            }

            if (! $version->isComplete()) {
                $total = $version->totalPercentage()->toScale(3, RoundingMode::HalfUp);

                throw new FormulaStateException("The recipe adds up to {$total}% and has no QS line to make up the rest. Add the filler (usually water) as QS, or correct the percentages, before activating.");
            }

            $now = now();

            $formula->versions()
                ->where('status', FormulaVersionStatus::Active->value)
                ->update(['status' => FormulaVersionStatus::Superseded->value, 'superseded_at' => $now, 'updated_at' => $now]);

            $version->fill([
                'status' => FormulaVersionStatus::Active,
                'approved_by' => $userId,
                'approved_at' => $now,
                'activated_at' => $now,
            ])->save();

            $formula->fill([
                'status' => FormulaStatus::Active,
                'active_version_id' => $version->getKey(),
                'updated_by' => $userId,
            ])->save();

            if (($user = User::find($userId)) !== null) {
                $this->security->record($user, FormulaAccessAction::Activated, $formula, $version);
            }

            return $version->refresh();
        });
    }

    /**
     * Throw away a draft. The formula keeps its other versions.
     */
    public function discardVersion(FormulaVersion $version): void
    {
        DB::transaction(function () use ($version): void {
            $version = FormulaVersion::query()->lockForUpdate()->findOrFail($version->getKey());

            if ($version->status !== FormulaVersionStatus::Draft) {
                throw new FormulaStateException("Version {$version->version_number} is {$version->status->label()}; only drafts can be discarded.");
            }

            if ($version->formula->versions()->count() === 1) {
                throw new FormulaStateException('This is the only version. Delete the formula instead.');
            }

            $version->delete();
        });
    }

    public function archive(Formula $formula, ?int $userId): Formula
    {
        if ($formula->isArchived()) {
            return $formula;
        }

        $formula->fill(['status' => FormulaStatus::Archived, 'updated_by' => $userId])->save();

        return $formula;
    }

    public function restore(Formula $formula, ?int $userId): Formula
    {
        if (! $formula->isArchived()) {
            return $formula;
        }

        $formula->fill([
            'status' => $formula->active_version_id === null ? FormulaStatus::Draft : FormulaStatus::Active,
            'updated_by' => $userId,
        ])->save();

        return $formula;
    }

    /**
     * Soft-delete a formula and everything under it. Recoverable by an
     * administrator; the access trail keeps its history either way.
     */
    public function delete(Formula $formula, ?int $userId): void
    {
        DB::transaction(function () use ($formula, $userId): void {
            $formula->forceFill(['updated_by' => $userId, 'active_version_id' => null])->saveQuietly();
            $formula->delete();
        });
    }

    /**
     * Validate and store a recipe's lines, replacing whatever the version
     * had. Line numbers are reassigned in the order given.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function writeLines(FormulaVersion $version, array $lines): void
    {
        if ($lines === []) {
            throw new FormulaStateException('A recipe needs at least one ingredient.');
        }

        $itemIds = array_values(array_unique(array_map(static fn (array $line): int => (int) $line['item_id'], $lines)));

        $items = Item::query()->whereIn('id', $itemIds)->get()->keyBy('id');

        foreach ($itemIds as $id) {
            $item = $items->get($id);

            if ($item === null) {
                throw new FormulaStateException("Ingredient #{$id} does not exist.");
            }

            if (! in_array($item->type, self::INGREDIENT_TYPES, strict: true)) {
                throw new FormulaStateException("{$item->code} {$item->name} is not a raw material and cannot go into a recipe.");
            }
        }

        $seen = [];
        $total = BigDecimal::zero();
        $qsCount = 0;
        $rows = [];

        foreach (array_values($lines) as $index => $line) {
            $itemId = (int) $line['item_id'];

            if (isset($seen[$itemId])) {
                $item = $items->get($itemId);

                throw new FormulaStateException("{$item->name} appears twice. Combine the two lines.");
            }

            $seen[$itemId] = true;

            $isQs = (bool) ($line['is_qs'] ?? false);
            $percentage = $this->percentage($line['percentage'] ?? null);

            if ($isQs) {
                $qsCount++;
                $percentage = null;
            } elseif ($percentage !== null) {
                $total = $total->plus($percentage);
            }

            $rows[] = [
                'line_no' => $index + 1,
                'item_id' => $itemId,
                'inci_name' => $this->nullable($line['inci_name'] ?? null, 255),
                'percentage' => $percentage?->__toString(),
                'is_qs' => $isQs,
                'qs_note' => $this->nullable($line['qs_note'] ?? null, 64),
                'grade' => $this->nullable(isset($line['grade']) ? strtoupper((string) $line['grade']) : null, 8),
                'phase' => $this->nullable($line['phase'] ?? null, 8),
                'purpose' => $this->nullable($line['purpose'] ?? null, 128),
                'notes' => $this->nullable($line['notes'] ?? null, 500),
            ];
        }

        if ($qsCount > 1) {
            throw new FormulaStateException('Only one ingredient can be the QS filler.');
        }

        if ($total->isGreaterThan(100)) {
            throw new FormulaStateException('The percentages add up to '.$total->toScale(3, RoundingMode::HalfUp).'%, which is more than 100%.');
        }

        $version->ingredients()->delete();

        foreach ($rows as $row) {
            $version->ingredients()->create($row);
        }

        $version->total_percentage = $total->toScale(6, RoundingMode::HalfUp)->__toString();
        $version->unsetRelation('ingredients');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    private function createVersion(Formula $formula, array $attributes, array $lines, ?int $userId, int $versionNumber): FormulaVersion
    {
        $version = new FormulaVersion([
            'formula_id' => $formula->getKey(),
            'version_number' => $versionNumber,
            'status' => FormulaVersionStatus::Draft,
            'batch_size' => $attributes['batch_size'] ?? '100',
            'batch_uom_id' => $attributes['batch_uom_id'],
            'notes' => $attributes['notes'] ?? null,
            'change_summary' => $attributes['change_summary'] ?? null,
            'source' => $attributes['source'] ?? 'manual',
            'source_reference' => $attributes['source_reference'] ?? null,
            'created_by' => $userId,
        ]);

        $version->save();
        $this->writeLines($version, $lines);
        $version->save();

        return $version;
    }

    private function nextCode(): string
    {
        return sprintf('FRM-%04d', $this->sequences->next('formula'));
    }

    private function percentage(mixed $value): ?BigDecimal
    {
        if ($value === null || $value === '') {
            return null;
        }

        $percentage = BigDecimal::of(is_string($value) ? trim($value) : $value);

        if ($percentage->isNegative() || $percentage->isGreaterThan(100)) {
            throw new FormulaStateException("A percentage must be between 0 and 100; got {$percentage}.");
        }

        return $percentage->toScale(6, RoundingMode::HalfUp);
    }

    private function nullable(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
