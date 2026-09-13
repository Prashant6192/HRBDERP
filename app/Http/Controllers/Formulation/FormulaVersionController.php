<?php

declare(strict_types=1);

namespace App\Http\Controllers\Formulation;

use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Formulation\Exceptions\FormulaStateException;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Formulation\Services\FormulaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The version lifecycle: open a draft, activate it, or throw it away.
 * Editing a draft's recipe goes through FormulaController::update.
 */
class FormulaVersionController extends Controller
{
    public function __construct(
        private readonly FormulaService $formulas,
        private readonly ApprovalService $approvals,
    ) {}

    public function store(Request $request, Formula $formula): RedirectResponse
    {
        $this->authorize('create', FormulaVersion::class);
        $this->authorize('update', $formula);

        try {
            $version = $this->formulas->newVersion($formula, $request->user()->id, changeSummary: $request->input('change_summary'));
        } catch (FormulaStateException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return redirect()->route('formulas.edit', $formula)
            ->withToast('success', "Draft v{$version->version_number} opened from the current recipe. Make your changes and activate it when ready.");
    }

    public function activate(Request $request, Formula $formula, FormulaVersion $version): RedirectResponse
    {
        $this->authorize('activate', $version);

        // Maker-checker: the author of a draft cannot be the one who makes
        // it the recipe production uses. Their request goes to a checker.
        if ((int) $version->created_by === (int) $request->user()->id && ! $request->user()->isSuperAdmin()) {
            try {
                $this->approvals->request($version, 'formula.activate', $request->user(), ['triggers' => [['key' => 'maker_checker', 'reason' => 'The author of a recipe version cannot activate it.']]], $request->input('note'));
            } catch (ApprovalException $e) {
                return back()->withToast('error', $e->getMessage());
            }

            return redirect()->route('formulas.show', $formula)
                ->withToast('info', "v{$version->version_number} sent for approval: you wrote it, so someone else must sign it off.");
        }

        try {
            $this->formulas->activate($version, $request->user()->id);
        } catch (FormulaStateException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return redirect()->route('formulas.show', $formula)
            ->withToast('success', "v{$version->version_number} is now the active recipe for {$formula->name}.");
    }

    public function destroy(Request $request, Formula $formula, FormulaVersion $version): RedirectResponse
    {
        $this->authorize('delete', $version);

        try {
            $this->formulas->discardVersion($version);
        } catch (FormulaStateException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return redirect()->route('formulas.show', $formula)->withToast('success', "Draft v{$version->version_number} discarded.");
    }
}
