<?php

declare(strict_types=1);

namespace App\Http\Controllers\Formulation;

use App\Domain\Formulation\DTOs\ImportOptions;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Services\FormulaImportService;
use App\Domain\Formulation\Services\FormulationSheetParser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Formulation\CommitFormulaImportRequest;
use App\Http\Requests\Formulation\PreviewFormulaImportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Import formulations from a workbook, with a preview in between.
 *
 * The uploaded file waits in private storage under a random token while
 * the person reviews the plan; committing re-reads the same file, imports
 * it and deletes it. Nothing about the recipe is kept in the session.
 */
class FormulaImportController extends Controller
{
    private const string DIRECTORY = 'formula-imports';

    private const string SESSION_KEY = 'formula_import';

    public function __construct(
        private readonly FormulationSheetParser $parser,
        private readonly FormulaImportService $importer,
    ) {}

    public function create(Request $request): Response
    {
        $this->authorize('import', Formula::class);

        $pending = $request->session()->get(self::SESSION_KEY);

        // A preview whose file has since gone (a restart, a cleanup) is stale.
        if ($pending !== null && ! Storage::disk('local')->exists($this->path($pending['token']))) {
            $request->session()->forget(self::SESSION_KEY);
            $pending = null;
        }

        return Inertia::render('formulas/import', [
            'pending' => $pending,
            'can' => [
                'activate' => $request->user()->can('formula.approve'),
            ],
        ]);
    }

    public function preview(PreviewFormulaImportRequest $request): RedirectResponse
    {
        $this->authorize('import', Formula::class);

        $this->discardPending($request);

        $token = Str::random(40);
        $file = $request->file('workbook');

        Storage::disk('local')->putFileAs(self::DIRECTORY, $file, "{$token}.xlsx");

        $options = $this->options($request);

        try {
            $workbook = $this->parser->parseFile(Storage::disk('local')->path($this->path($token)));
            $plan = $this->importer->plan($workbook, $options);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($this->path($token));

            report($e);

            return back()->withErrors(['workbook' => 'That workbook could not be read: '.$e->getMessage()]);
        }

        if ($plan->formulas === []) {
            Storage::disk('local')->delete($this->path($token));

            return back()->withErrors(['workbook' => 'No formulations were found in the workbook. Each sheet should hold one product: its name, then the ingredients with their percentages.']);
        }

        $request->session()->put(self::SESSION_KEY, [
            'token' => $token,
            'file_name' => $file->getClientOriginalName(),
            'options' => [
                'assume_water_qs' => $options->assumeWaterQs,
                'activate' => $options->activate,
            ],
            'plan' => $plan->toArray(),
        ]);

        return redirect()->route('formulas.imports.create');
    }

    public function store(CommitFormulaImportRequest $request): RedirectResponse
    {
        $this->authorize('import', Formula::class);

        $pending = $request->session()->get(self::SESSION_KEY);
        $token = $request->validated('token');

        if ($pending === null || ! hash_equals($pending['token'], $token) || ! Storage::disk('local')->exists($this->path($token))) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('formulas.imports.create')->withToast('error', 'The preview has expired. Upload the workbook again.');
        }

        $options = $this->options($request);

        try {
            $workbook = $this->parser->parseFile(Storage::disk('local')->path($this->path($token)));
            $result = $this->importer->import($workbook, $options, $request->user()->id);
        } catch (Throwable $e) {
            report($e);

            return back()->withToast('error', 'The import failed and nothing was written: '.$e->getMessage());
        } finally {
            Storage::disk('local')->delete($this->path($token));
            $request->session()->forget(self::SESSION_KEY);
        }

        $parts = [];

        if ($result->created !== []) {
            $parts[] = count($result->created).' formula'.(count($result->created) === 1 ? '' : 's').' created';
        }

        if ($result->versions !== []) {
            $parts[] = count($result->versions).' new version'.(count($result->versions) === 1 ? '' : 's');
        }

        if ($result->skipped !== []) {
            $parts[] = count($result->skipped).' skipped';
        }

        if ($result->materialsCreated !== []) {
            $parts[] = count($result->materialsCreated).' raw material'.(count($result->materialsCreated) === 1 ? '' : 's').' added to the master';
        }

        return redirect()->route('formulas.index')
            ->withToast('success', 'Import complete: '.($parts === [] ? 'nothing to do' : implode(', ', $parts)).'.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->authorize('import', Formula::class);

        $this->discardPending($request);

        return redirect()->route('formulas.imports.create')->withToast('success', 'Upload discarded.');
    }

    private function options(Request $request): ImportOptions
    {
        return new ImportOptions(
            assumeWaterQs: $request->boolean('assume_water_qs', true),
            activate: $request->boolean('activate') && $request->user()->can('formula.approve'),
        );
    }

    private function discardPending(Request $request): void
    {
        $pending = $request->session()->pull(self::SESSION_KEY);

        if ($pending !== null) {
            Storage::disk('local')->delete($this->path($pending['token']));
        }
    }

    private function path(string $token): string
    {
        return self::DIRECTORY.'/'.$token.'.xlsx';
    }
}
