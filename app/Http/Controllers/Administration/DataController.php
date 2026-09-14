<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administration;

use App\Domain\Administration\Exceptions\DataResetException;
use App\Domain\Administration\Services\DataResetService;
use App\Domain\Administration\Services\DemoFactoryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Clearing what testing left behind, and filling the system with a worked
 * example. Both are the system administrator's alone, and clearing asks
 * for the company's name typed out before it does anything.
 */
class DataController extends Controller
{
    public function __construct(
        private readonly DataResetService $reset,
        private readonly DemoFactoryService $demo,
    ) {}

    public function index(Request $request): Response
    {
        $this->guard($request);

        return Inertia::render('administration/data', [
            'scopes' => collect(DataResetService::SCOPES)
                ->map(fn (array $scope, string $key) => [
                    'key' => $key,
                    'label' => $scope['label'],
                    'description' => $scope['description'],
                    'requires' => $scope['requires'],
                    'danger' => $scope['danger'] ?? false,
                    'tables' => count($this->reset->tablesFor($key)),
                ])
                ->values()->all(),
            'counts' => $this->reset->counts(),
            'company' => (string) config('erp.company.name'),
            'environment' => app()->environment(),
        ]);
    }

    public function clear(Request $request): RedirectResponse
    {
        $this->guard($request);

        $company = (string) config('erp.company.name');

        $data = $request->validate([
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(array_keys(DataResetService::SCOPES))],
            'restart_numbering' => ['sometimes', 'boolean'],
            // Nothing is cleared until the company's name is typed out.
            'confirmation' => ['required', 'string', Rule::in([$company])],
        ], [
            'scopes.required' => 'Choose at least one kind of data to clear.',
            'confirmation.in' => "Type the company name exactly — {$company} — to confirm.",
        ]);

        try {
            $cleared = $this->reset->reset($data['scopes'], (bool) ($data['restart_numbering'] ?? false));
        } catch (DataResetException $e) {
            return back()->withErrors(['scopes' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Data reset failed', ['user_id' => $request->user()->id, 'scopes' => $data['scopes'], 'error' => $e->getMessage()]);

            return back()->withErrors(['scopes' => 'Nothing was cleared: '.$e->getMessage()]);
        }

        $rows = array_sum($cleared);
        $resolved = $this->reset->withDependencies($data['scopes']);
        $names = implode(', ', array_map(fn (string $key) => strtolower(DataResetService::SCOPES[$key]['label']), $resolved));

        Log::warning('ERP data cleared', ['user_id' => $request->user()->id, 'scopes' => $resolved, 'rows' => $cleared]);

        return back()->withToast('success', $rows === 0
            ? 'There was nothing to clear.'
            : number_format($rows).' record'.($rows === 1 ? '' : 's').' cleared: '.$names.'. People, roles and the audit trail are untouched.');
    }

    public function fillDemo(Request $request): RedirectResponse
    {
        $this->guard($request);

        $request->validate([
            'confirmation' => ['required', 'string', Rule::in([(string) config('erp.company.name')])],
        ], [
            'confirmation.in' => 'Type the company name exactly to confirm.',
        ]);

        try {
            $made = $this->demo->fill($request->user());
        } catch (Throwable $e) {
            Log::error('Demo fill failed', ['user_id' => $request->user()->id, 'error' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()]);

            return back()->withErrors(['demo' => 'The demo data could not be filled in: '.$e->getMessage()]);
        }

        return back()->withToast('success', 'Demo factory filled in: '.implode(' · ', array_values($made)));
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Clearing and filling data is reserved for the system administrator.');
    }
}
