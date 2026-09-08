<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administration;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Support\Tables\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only view of the audit trail.
 *
 * There is deliberately no store, update or destroy action here, and no route
 * that could reach one.
 */
class AuditLogController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTABLE = ['created_at', 'action', 'user_name'];

    public function index(Request $request): Response
    {
        Gate::authorize('audit.view');

        $table = TableQuery::fromRequest($request, allowedFilters: ['action', 'user', 'from', 'to']);

        $query = AuditLog::query()->with('user:id,name');

        if ($table->search !== '') {
            $term = $table->search;

            $query->where(function ($query) use ($term): void {
                $query->where('user_name', 'ilike', "%{$term}%")
                    ->orWhere('auditable_label', 'ilike', "%{$term}%")
                    ->orWhere('description', 'ilike', "%{$term}%")
                    ->orWhere('auditable_type', 'ilike', "%{$term}%");
            });
        }

        if ($action = $table->filter('action')) {
            $query->where('action', $action);
        }

        if ($user = $table->filter('user')) {
            $query->where('user_id', $user);
        }

        if ($from = $table->filter('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $table->filter('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        return Inertia::render('audit/index', [
            'entries' => $table->paginate(
                $table->applySorting($query, self::SORTABLE, fallback: 'created_at')
            ),
            'table' => $table->toArray(),
            'actions' => $this->actionOptions(),
            'can' => ['export' => $request->user()->can('audit.export')],
        ]);
    }

    public function show(AuditLog $auditLog): Response
    {
        Gate::authorize('audit.view');

        $auditLog->load('user:id,name,email');

        return Inertia::render('audit/show', [
            'entry' => $auditLog,
            'changes' => $auditLog->changes(),
        ]);
    }

    /**
     * @return list<array{value: string, label: string, sensitive: bool}>
     */
    private function actionOptions(): array
    {
        return array_map(
            static fn (AuditAction $action): array => [
                'value' => $action->value,
                'label' => $action->label(),
                'sensitive' => $action->isSensitive(),
            ],
            AuditAction::cases(),
        );
    }
}
