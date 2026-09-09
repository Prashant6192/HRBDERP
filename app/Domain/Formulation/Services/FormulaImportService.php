<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Services;

use App\Domain\Formulation\DTOs\ImportOptions;
use App\Domain\Formulation\DTOs\ImportPlan;
use App\Domain\Formulation\DTOs\ImportResult;
use App\Domain\Formulation\DTOs\ParsedFormula;
use App\Domain\Formulation\DTOs\ParsedIngredient;
use App\Domain\Formulation\DTOs\ParsedWorkbook;
use App\Domain\Formulation\Enums\FormulaAccessAction;
use App\Domain\Formulation\Enums\MaterialGrade;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Models\FormulaIngredient;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\MasterData\Services\ItemCodeGenerator;
use App\Domain\Measurement\Models\Uom;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Brings a parsed workbook into the formulation module.
 *
 * Two steps, so a person sees the consequences first: plan() works out
 * which formulas would be created or revised and which materials would be
 * added to the master data; import() carries that plan out in one
 * transaction. Nothing is written by plan().
 */
class FormulaImportService
{
    private const string WATER = 'Purified Water';

    /**
     * Names chemists use for the same filler.
     *
     * @var array<string, string>
     */
    private const array ALIASES = [
        'aqua' => 'purified water',
        'water' => 'purified water',
        'di water' => 'purified water',
        'dm water' => 'purified water',
        'demineralised water' => 'purified water',
        'demineralized water' => 'purified water',
        'deionised water' => 'purified water',
        'deionized water' => 'purified water',
        'ro water' => 'purified water',
    ];

    public function __construct(
        private readonly FormulaService $formulas,
        private readonly ItemCodeGenerator $codes,
        private readonly FormulaSecurityService $security,
    ) {}

    public function plan(ParsedWorkbook $workbook, ImportOptions $options): ImportPlan
    {
        $plan = new ImportPlan(skippedSheets: $workbook->skippedSheets);
        $materials = $this->preloadMaterials($workbook);
        $seenInWorkbook = [];

        foreach ($workbook->formulas as $parsed) {
            $ingredients = $this->withAssumedWater($parsed, $options);
            $nameKey = $this->normalise($parsed->name);

            $lines = array_map(fn (ParsedIngredient $i): array => $this->planLine($i, $materials), $ingredients);
            $signature = $this->signature($lines);

            $existing = Formula::query()->withTrashed()->whereRaw('lower(name) = ?', [$nameKey])->orderByDesc('id')->first();

            if ($existing?->trashed()) {
                $existing = null;
            }

            $product = Product::query()->whereRaw('lower(name) = ?', [$nameKey])->first();

            $warnings = $parsed->warnings;
            $action = 'create';

            if (isset($seenInWorkbook[$nameKey])) {
                if ($seenInWorkbook[$nameKey] === $signature) {
                    $action = 'skip_duplicate';
                    $warnings[] = 'Another sheet in this workbook already holds this exact recipe.';
                } else {
                    $action = 'new_version';
                    $warnings[] = 'Another sheet in this workbook holds a different recipe for this product; this one becomes the next version.';
                }
            } elseif ($existing !== null) {
                $latest = $existing->versions()->with('ingredients.item')->first();

                if ($latest !== null && $this->signatureOfVersion($latest) === $signature) {
                    $action = 'skip_identical';
                    $warnings[] = "{$existing->code} already holds this exact recipe (v{$latest->version_number}).";
                } elseif ($existing->draftVersion() !== null) {
                    $action = 'skip_draft';
                    $warnings[] = "{$existing->code} has a draft open; finish or discard it before importing a new version.";
                } else {
                    $action = 'new_version';
                }
            }

            if (! in_array($action, ['skip_duplicate', 'skip_identical', 'skip_draft'], strict: true)) {
                $seenInWorkbook[$nameKey] = $signature;
            }

            $total = array_reduce(
                $lines,
                static fn (BigDecimal $carry, array $l): BigDecimal => $l['percentage'] === null ? $carry : $carry->plus(BigDecimal::of($l['percentage'])),
                BigDecimal::zero(),
            );

            $plan->formulas[] = [
                'sheet' => $parsed->sheet,
                'name' => $parsed->name,
                'layout' => $parsed->layout,
                'batch_size' => $parsed->batchSize,
                'batch_uom' => $parsed->batchUomCode,
                'action' => $action,
                'existing_formula_id' => $existing?->id,
                'existing_code' => $existing?->code,
                'product_id' => $product?->id,
                'product_name' => $product?->name,
                'total_percentage' => $total->toScale(3, RoundingMode::HalfUp)->__toString(),
                'has_qs' => (bool) array_filter($lines, static fn (array $l): bool => $l['is_qs']),
                'lines' => $lines,
                'warnings' => $warnings,
            ];
        }

        return $plan;
    }

    public function import(ParsedWorkbook $workbook, ImportOptions $options, ?int $userId): ImportResult
    {
        $plan = $this->plan($workbook, $options);

        return DB::transaction(function () use ($plan, $options, $userId): ImportResult {
            $result = new ImportResult;
            $createdMaterials = [];

            foreach ($plan->formulas as $entry) {
                if (str_starts_with($entry['action'], 'skip')) {
                    $result->skipped[] = [
                        'sheet' => $entry['sheet'],
                        'name' => $entry['name'],
                        'reason' => $entry['warnings'] === [] ? $entry['action'] : end($entry['warnings']),
                    ];

                    continue;
                }

                $lines = [];

                foreach ($entry['lines'] as $line) {
                    $itemId = $line['item_id'];

                    if ($itemId === null) {
                        if (! $options->createMissingMaterials) {
                            throw new InvalidArgumentException("\"{$line['name']}\" is not in the raw material master and creating materials was not allowed.");
                        }

                        $itemId = $createdMaterials[$line['key']] ??= $this->createMaterial($line, $userId, $result);
                    }

                    $lines[] = [
                        'item_id' => $itemId,
                        'inci_name' => $line['inci_name'],
                        'percentage' => $line['percentage'],
                        'is_qs' => $line['is_qs'],
                        'qs_note' => $line['qs_note'],
                        'grade' => $line['grade'],
                        'purpose' => $line['purpose'],
                    ];
                }

                $batchUom = Uom::query()->where('code', $entry['batch_uom'])->first()
                    ?? Uom::query()->where('code', 'G')->firstOrFail();

                $attributes = [
                    'batch_size' => $entry['batch_size'],
                    'batch_uom_id' => $batchUom->id,
                    'source' => 'import',
                    'source_reference' => $entry['sheet'],
                    'change_summary' => 'Imported from spreadsheet sheet "'.$entry['sheet'].'"',
                ];

                if ($entry['action'] === 'create') {
                    $formula = $this->formulas->create([
                        'name' => $entry['name'],
                        'product_id' => $entry['product_id'],
                    ] + $attributes, $lines, $userId);

                    $version = $formula->versions()->first();
                    $bucket = 'created';
                } else {
                    $formula = Formula::query()->findOrFail($entry['existing_formula_id']);
                    $version = $this->formulas->newVersion($formula, $userId);
                    $version = $this->formulas->updateVersion($version, $attributes, $lines, $userId);
                    $bucket = 'versions';
                }

                $activated = false;

                if ($options->activate && $userId !== null && $version->fresh()->load('ingredients')->isComplete()) {
                    $this->formulas->activate($version, $userId);
                    $activated = true;
                }

                $result->{$bucket}[] = [
                    'formula_id' => $formula->id,
                    'code' => $formula->code,
                    'name' => $formula->name,
                    'version' => $version->version_number,
                    'activated' => $activated,
                ];
            }

            if ($userId !== null && ($user = User::find($userId)) !== null) {
                $this->security->record($user, FormulaAccessAction::Imported, context: [
                    'created' => count($result->created),
                    'versions' => count($result->versions),
                    'skipped' => count($result->skipped),
                    'materials_created' => count($result->materialsCreated),
                ]);
            }

            return $result;
        });
    }

    // ---- Planning helpers ------------------------------------------------

    /**
     * @param  Collection<string, Item>  $materials
     * @return array<string, mixed>
     */
    private function planLine(ParsedIngredient $ingredient, Collection $materials): array
    {
        $key = $this->materialKey($ingredient->name);
        $item = $materials->get($key);

        if ($item === null && $ingredient->inciName !== null) {
            $item = $materials->get($this->materialKey($ingredient->inciName));
        }

        return [
            'line_no' => $ingredient->lineNo,
            'key' => $key,
            'name' => $item?->name ?? $this->displayName($ingredient->name),
            'inci_name' => $ingredient->inciName,
            'trade_name' => $ingredient->tradeName,
            'percentage' => $ingredient->percentage,
            'is_qs' => $ingredient->isQs,
            'qs_note' => $ingredient->qsNote,
            'grade' => MaterialGrade::tryFromLabel($ingredient->grade)?->value,
            'purpose' => $ingredient->purpose,
            'as_required' => $ingredient->isAsRequired(),
            'item_id' => $item?->id,
            'item_code' => $item?->code,
            'action' => $item === null ? 'create' : 'match',
            'warnings' => $ingredient->warnings,
        ];
    }

    /**
     * @return list<ParsedIngredient>
     */
    private function withAssumedWater(ParsedFormula $parsed, ImportOptions $options): array
    {
        $ingredients = $parsed->ingredients;

        if (! $options->assumeWaterQs || $parsed->hasQs() || $parsed->totalPercentage()->isGreaterThanOrEqualTo(100)) {
            return $ingredients;
        }

        $water = new ParsedIngredient(
            lineNo: count($ingredients) + 1,
            name: self::WATER,
            inciName: 'Aqua',
            isQs: true,
            qsNote: 'QS to '.$parsed->batchSize.' '.strtolower($parsed->batchUomCode),
            grade: 'IP',
            purpose: 'Solvent',
            warnings: ['Added by the import: the sheet lists no filler, so water is assumed to make up the rest.'],
        );

        // Water goes first, as chemists write it.
        array_unshift($ingredients, $water);

        foreach ($ingredients as $index => $ingredient) {
            $ingredient->lineNo = $index + 1;
        }

        return $ingredients;
    }

    /**
     * Every raw material any sheet mentions, keyed by normalised name and
     * by normalised INCI name, in one query.
     *
     * @return Collection<string, Item>
     */
    private function preloadMaterials(ParsedWorkbook $workbook): Collection
    {
        $keys = [$this->materialKey(self::WATER)];

        foreach ($workbook->formulas as $formula) {
            foreach ($formula->ingredients as $ingredient) {
                $keys[] = $this->materialKey($ingredient->name);

                if ($ingredient->inciName !== null) {
                    $keys[] = $this->materialKey($ingredient->inciName);
                }
            }
        }

        $keys = array_values(array_unique($keys));

        $items = Item::query()
            ->whereIn('type', [ItemType::RawMaterial->value, ItemType::SemiFinished->value])
            ->where(function ($query) use ($keys): void {
                $query->whereIn(DB::raw('lower(name)'), $keys)
                    ->orWhereIn(DB::raw('lower(inci_name)'), $keys);
            })
            ->orderBy('id')
            ->get();

        $byKey = new Collection;

        foreach ($items as $item) {
            foreach ([$item->name, $item->inci_name] as $candidate) {
                if ($candidate === null) {
                    continue;
                }

                $key = $this->materialKey($candidate);

                if (! $byKey->has($key)) {
                    $byKey->put($key, $item);
                }
            }
        }

        return $byKey;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function createMaterial(array $line, ?int $userId, ImportResult $result): int
    {
        $isWater = $line['key'] === $this->materialKey(self::WATER);

        $material = RawMaterial::create([
            'code' => $this->codes->next(ItemType::RawMaterial),
            'name' => $line['name'],
            'inci_name' => $line['inci_name'] ?? $line['name'],
            'brand' => $line['trade_name'],
            'stock_uom_id' => Uom::query()->where('code', 'KG')->value('id') ?? Uom::query()->where('code', 'G')->value('id'),
            'density_g_per_ml' => $isWater ? '1' : null,
            'is_batch_tracked' => true,
            'requires_qc' => ! $isWater,
            'is_active' => true,
            'description' => 'Created by the formulation import.',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $result->materialsCreated[] = ['item_id' => $material->id, 'code' => $material->code, 'name' => $material->name];

        return $material->id;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function signature(array $lines): string
    {
        $parts = array_map(static fn (array $l): string => $l['key'].'|'.($l['percentage'] === null ? ($l['is_qs'] ? 'qs' : 'ar') : BigDecimal::of($l['percentage'])->toScale(6, RoundingMode::HalfUp)->__toString()), $lines);
        sort($parts);

        return sha1(implode("\n", $parts));
    }

    private function signatureOfVersion(FormulaVersion $version): string
    {
        $lines = $version->ingredients->map(fn (FormulaIngredient $line): array => [
            'key' => $this->materialKey($line->item->name),
            'percentage' => $line->percentage,
            'is_qs' => $line->is_qs,
        ])->all();

        return $this->signature($lines);
    }

    private function materialKey(string $name): string
    {
        $key = $this->normalise($name);

        return self::ALIASES[$key] ?? $key;
    }

    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }

    private function displayName(string $name): string
    {
        $key = $this->normalise($name);

        if (isset(self::ALIASES[$key])) {
            return self::WATER;
        }

        return trim($name);
    }
}
