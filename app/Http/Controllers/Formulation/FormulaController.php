<?php

declare(strict_types=1);

namespace App\Http\Controllers\Formulation;

use App\Domain\Formulation\Enums\FormulaAccessAction;
use App\Domain\Formulation\Enums\FormulaStatus;
use App\Domain\Formulation\Enums\FormulaVersionStatus;
use App\Domain\Formulation\Enums\MaterialGrade;
use App\Domain\Formulation\Exceptions\FormulaStateException;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Models\FormulaIngredient;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Formulation\Services\FormulaScalingService;
use App\Domain\Formulation\Services\FormulaSecurityService;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Http\Controllers\Controller;
use App\Http\Requests\Formulation\StoreFormulaRequest;
use App\Http\Requests\Formulation\UpdateFormulaRequest;
use App\Support\Tables\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Formulas: the list anyone with formula.view may see, and the recipes only
 * an unlocked user may open.
 *
 * Nothing in index() loads an ingredient. Everything from show() onwards
 * sits behind the formula.unlocked middleware (see the routes) and records
 * itself in the formula access trail.
 */
class FormulaController extends Controller
{
    private const array SORTABLE = ['code', 'name', 'status', 'updated_at'];

    public function __construct(
        private readonly FormulaService $formulas,
        private readonly FormulaScalingService $scaling,
        private readonly FormulaSecurityService $security,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Formula::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['status']);

        $query = Formula::query()
            ->with(['product:id,code,name', 'activeVersion:id,version_number,activated_at'])
            ->withCount('versions')
            ->search($table->search);

        if ($status = $table->filter('status')) {
            $query->where('status', $status);
        }

        return Inertia::render('formulas/index', [
            'formulas' => $table->paginate($table->applySorting($query, self::SORTABLE, fallback: 'name')),
            'table' => $table->toArray(),
            'statuses' => array_map(static fn (FormulaStatus $s): array => ['value' => $s->value, 'label' => $s->label()], FormulaStatus::cases()),
            'can' => [
                'create' => $request->user()->can('create', Formula::class),
                'import' => $request->user()->can('formula.import'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Formula::class);

        return Inertia::render('formulas/form', [
            'mode' => 'create',
            'formula' => null,
            'version' => null,
            'lines' => [],
            ...$this->formOptions(),
        ]);
    }

    public function store(StoreFormulaRequest $request): RedirectResponse
    {
        $this->authorize('create', Formula::class);

        try {
            $formula = $this->formulas->create(
                $request->safe()->except('lines'),
                $request->validated('lines'),
                $request->user()->id,
            );
        } catch (FormulaStateException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        $this->security->record($request->user(), FormulaAccessAction::Edited, $formula, context: ['event' => 'created'], ip: $request->ip(), userAgent: $request->userAgent());

        return redirect()->route('formulas.show', $formula)->withToast('success', "{$formula->code} {$formula->name} created as a draft. Review it and activate when it is right.");
    }

    public function show(Request $request, Formula $formula): Response
    {
        $this->authorize('view', $formula);

        $formula->load(['product:id,code,name', 'createdBy:id,name']);

        $versions = $formula->versions()
            ->with(['createdBy:id,name', 'approvedBy:id,name', 'batchUom:id,code'])
            ->get();

        $requested = $request->integer('version');
        $version = $requested > 0
            ? $versions->firstWhere('id', $requested)
            : null;

        $version ??= $versions->firstWhere('id', $formula->active_version_id) ?? $versions->first();

        $ingredients = [];
        $scaled = null;

        if ($version !== null) {
            $version->load(['ingredients.item:id,code,name,inci_name,stock_uom_id,density_g_per_ml', 'ingredients.item.stockUom:id,code']);

            $ingredients = $version->ingredients->map(static fn (FormulaIngredient $line): array => [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'item_id' => $line->item_id,
                'item_code' => $line->item->code,
                'item_name' => $line->item->name,
                'inci_name' => $line->inci_name ?? $line->item->inci_name,
                'stock_uom' => $line->item->stockUom?->code,
                'percentage' => $line->percentage,
                'is_qs' => $line->is_qs,
                'qs_note' => $line->qs_note,
                'as_required' => $line->isAsRequired(),
                'grade' => $line->grade,
                'phase' => $line->phase,
                'purpose' => $line->purpose,
                'notes' => $line->notes,
            ])->all();

            $this->security->record($request->user(), FormulaAccessAction::Viewed, $formula, $version, ip: $request->ip(), userAgent: $request->userAgent());

            if ($request->filled('batch') && is_numeric($request->input('batch')) && (float) $request->input('batch') > 0) {
                $uom = Uom::query()->active()->find($request->integer('batch_uom')) ?? $version->batchUom;
                $scaled = $this->scaling->scale($version, (string) $request->input('batch'), $uom)->toArray();

                $this->security->record($request->user(), FormulaAccessAction::Scaled, $formula, $version, context: ['batch' => (string) $request->input('batch'), 'uom' => $uom->code], ip: $request->ip(), userAgent: $request->userAgent());
            }
        }

        $draft = $versions->firstWhere('status', FormulaVersionStatus::Draft);

        return Inertia::render('formulas/show', [
            'formula' => $formula,
            'version' => $version,
            'ingredients' => $ingredients,
            'versions' => $versions->map(static fn (FormulaVersion $v): array => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'status' => $v->status->value,
                'total_percentage' => $v->total_percentage,
                'batch_size' => $v->batch_size,
                'batch_uom' => $v->batchUom?->code,
                'change_summary' => $v->change_summary,
                'source' => $v->source,
                'created_at' => $v->created_at?->toIso8601String(),
                'created_by' => $v->createdBy?->name,
                'activated_at' => $v->activated_at?->toIso8601String(),
                'approved_by' => $v->approvedBy?->name,
                'superseded_at' => $v->superseded_at?->toIso8601String(),
            ])->all(),
            'scaled' => $scaled,
            'scaleInput' => [
                'batch' => $request->input('batch', ''),
                'batch_uom' => $request->input('batch_uom', $version?->batch_uom_id),
            ],
            'uoms' => $this->batchUomOptions(),
            'can' => [
                'update' => $request->user()->can('update', $formula),
                'approve' => $request->user()->can('approve', $formula),
                'archive' => $request->user()->can('archive', $formula),
                'delete' => $request->user()->can('delete', $formula),
                'has_draft' => $draft !== null,
                'draft_id' => $draft?->id,
            ],
        ]);
    }

    public function edit(Request $request, Formula $formula): Response|RedirectResponse
    {
        $this->authorize('update', $formula);

        $draft = $formula->draftVersion();

        if ($draft === null) {
            return redirect()->route('formulas.show', $formula)
                ->withToast('warning', 'This formula has no draft to edit. Open a new version to make changes.');
        }

        $draft->load('ingredients');

        return Inertia::render('formulas/form', [
            'mode' => 'edit',
            'formula' => $formula->only(['id', 'code', 'name', 'product_id', 'description', 'status']),
            'version' => $draft->only(['id', 'version_number', 'batch_size', 'batch_uom_id', 'notes', 'change_summary']),
            'lines' => $draft->ingredients->map(static fn (FormulaIngredient $line): array => [
                'item_id' => (string) $line->item_id,
                'inci_name' => $line->inci_name ?? '',
                'percentage' => $line->percentage ?? '',
                'is_qs' => $line->is_qs,
                'qs_note' => $line->qs_note ?? '',
                'grade' => $line->grade ?? '',
                'phase' => $line->phase ?? '',
                'purpose' => $line->purpose ?? '',
                'notes' => $line->notes ?? '',
            ])->all(),
            ...$this->formOptions(),
        ]);
    }

    public function update(UpdateFormulaRequest $request, Formula $formula): RedirectResponse
    {
        $this->authorize('update', $formula);

        $draft = $formula->draftVersion();

        if ($draft === null) {
            return back()->withToast('error', 'This formula has no draft to save into. Open a new version first.');
        }

        try {
            $this->formulas->updateDetails($formula, $request->safe()->only(['name', 'product_id', 'description']), $request->user()->id);
            $this->formulas->updateVersion(
                $draft,
                $request->safe()->only(['batch_size', 'batch_uom_id', 'notes', 'change_summary']),
                $request->validated('lines'),
                $request->user()->id,
            );
        } catch (FormulaStateException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()->route('formulas.show', ['formula' => $formula, 'version' => $draft->id])
            ->withToast('success', "Draft v{$draft->version_number} saved.");
    }

    public function archive(Request $request, Formula $formula): RedirectResponse
    {
        $this->authorize('archive', $formula);

        $this->formulas->archive($formula, $request->user()->id);

        return back()->withToast('success', "{$formula->name} archived. It stays on record but is no longer offered for production.");
    }

    public function restore(Request $request, Formula $formula): RedirectResponse
    {
        $this->authorize('archive', $formula);

        $this->formulas->restore($formula, $request->user()->id);

        return back()->withToast('success', "{$formula->name} restored.");
    }

    public function destroy(Request $request, Formula $formula): RedirectResponse
    {
        $this->authorize('delete', $formula);

        $this->formulas->delete($formula, $request->user()->id);

        return redirect()->route('formulas.index')->withToast('success', "{$formula->code} {$formula->name} deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'products' => Product::query()->active()->orderBy('name')->get(['id', 'code', 'name'])
                ->map(static fn (Product $p): array => ['value' => $p->id, 'label' => "{$p->code} — {$p->name}"])
                ->all(),
            'materials' => Item::query()
                ->whereIn('type', [ItemType::RawMaterial->value, ItemType::SemiFinished->value])
                ->where('is_active', true)
                ->with('stockUom:id,code')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'inci_name', 'stock_uom_id'])
                ->map(static fn (Item $i): array => [
                    'value' => $i->id,
                    'label' => "{$i->name} ({$i->code})",
                    'inci_name' => $i->inci_name,
                    'stock_uom' => $i->stockUom?->code,
                ])
                ->all(),
            'uoms' => $this->batchUomOptions(),
            'grades' => MaterialGrade::options(),
        ];
    }

    /**
     * @return list<array{value: int, label: string, dimension: string}>
     */
    private function batchUomOptions(): array
    {
        return Uom::query()
            ->active()
            ->whereIn('dimension', ['mass', 'volume'])
            ->orderBy('dimension')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'dimension'])
            ->map(static fn (Uom $u): array => ['value' => $u->id, 'label' => "{$u->code} — {$u->name}", 'dimension' => $u->dimension->value])
            ->all();
    }
}
