<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administration;

use App\Domain\Administration\Exceptions\DataBackupException;
use App\Domain\Administration\Exceptions\DataResetException;
use App\Domain\Administration\Services\DataBackupService;
use App\Domain\Administration\Services\DataResetService;
use App\Domain\Administration\Services\DemoFactoryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Backing the whole ERP up and restoring it, clearing what testing left
 * behind, and filling the system with a worked example. All of it is the
 * system administrator's alone, and anything that changes data asks for
 * the company's name typed out before it does anything.
 */
class DataController extends Controller
{
    public function __construct(
        private readonly DataResetService $reset,
        private readonly DemoFactoryService $demo,
        private readonly DataBackupService $backup,
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
            // Copies kept on the server before each restore, newest first.
            'copies' => collect(Storage::disk(DataBackupService::DISK)->files(DataBackupService::COPIES_DIR))
                ->filter(fn (string $f) => str_ends_with($f, '.zip'))
                ->map(fn (string $f) => ['name' => basename($f), 'size' => Storage::disk(DataBackupService::DISK)->size($f), 'at' => date('c', Storage::disk(DataBackupService::DISK)->lastModified($f))])
                ->sortByDesc('at')->values()->all(),
            'uploadLimit' => $this->uploadLimit(),
        ]);
    }

    /**
     * The whole ERP as one file to keep somewhere safe.
     */
    public function download(Request $request): BinaryFileResponse
    {
        $this->guard($request);

        $path = $this->backup->export();

        Log::info('ERP backup downloaded', ['user_id' => $request->user()->id]);

        return response()->download($path, $this->backup->fileName(), ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    /**
     * Everything back from a backup file: people, stores, materials,
     * stock and its history, batches, uploads. A copy of what is there now
     * is kept on the server first.
     */
    public function restore(Request $request): RedirectResponse
    {
        $this->guard($request);

        $company = (string) config('erp.company.name');

        $data = $request->validate([
            'backup' => ['required', 'file', 'extensions:zip', 'max:2097152'],
            'confirmation' => ['required', 'string', Rule::in([$company])],
        ], [
            'backup.required' => 'Choose the backup file the ERP produced.',
            'backup.extensions' => 'Upload the .zip the ERP produced, unchanged.',
            'confirmation.in' => "Type the company name exactly — {$company} — to confirm.",
        ]);

        $file = $data['backup'];

        try {
            $report = $this->backup->restore($file->getRealPath(), $request->user()->id);
        } catch (DataBackupException $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Restore failed', ['user_id' => $request->user()->id, 'error' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()]);

            return back()->withErrors(['backup' => 'Nothing was changed: '.$e->getMessage()]);
        }

        Log::warning('ERP data restored from backup', ['user_id' => $request->user()->id, 'report' => $report]);

        $message = number_format($report['rows']).' record'.($report['rows'] === 1 ? '' : 's').' across '.$report['tables'].' tables and '.number_format($report['files']).' file'.($report['files'] === 1 ? '' : 's').' restored'
            .($report['deleted'] > 0 ? '; '.number_format($report['deleted']).' newer record'.($report['deleted'] === 1 ? '' : 's').' removed' : '')
            .($report['copy'] !== null ? '. A copy of what was there before is kept on the server as '.basename($report['copy']).'.' : '.');

        if ($report['warnings'] !== []) {
            $message .= ' '.implode(' ', $report['warnings']);
        }

        return back()->withToast('success', $message);
    }

    /**
     * The smaller of PHP's two upload ceilings, in bytes, so the screen can
     * say how big a backup it can take.
     */
    private function uploadLimit(): int
    {
        $bytes = static function (string $value): int {
            $value = trim($value);
            $unit = strtolower(substr($value, -1));
            $number = (int) $value;

            return match ($unit) {
                'g' => $number * 1024 ** 3,
                'm' => $number * 1024 ** 2,
                'k' => $number * 1024,
                default => (int) $value,
            };
        };

        return min($bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size')));
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
