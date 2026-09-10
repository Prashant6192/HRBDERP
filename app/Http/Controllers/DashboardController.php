<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Reporting\Services\DashboardService;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first screen of the day.
 *
 * Every card is gated by the permission of the module it summarises, so a
 * figure never appears in front of someone who could not open the screen
 * behind it. Cards the user may not see are sent as null and not rendered.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $days = in_array($request->integer('days'), [7, 30, 90], strict: true) ? $request->integer('days') : 30;

        $kpis = $this->dashboard->kpis($user);

        return Inertia::render('dashboard', [
            'greeting' => [
                'name' => $user->name,
                'first_name' => explode(' ', trim($user->name))[0],
                'date' => now()->toIso8601String(),
                'roles' => $user->getRoleNames()->values()->all(),
            ],
            'headlines' => $this->dashboard->headlines($kpis),
            'kpis' => $kpis,
            'period' => ['days' => $days],

            'stores' => fn () => $user->can('inventory.view') ? $this->dashboard->storeLevels() : null,
            'attention' => fn () => $user->can('inventory.view') ? $this->dashboard->attention() : null,
            'expiring' => fn () => $user->can('inventory.view') ? $this->dashboard->expiring() : null,
            'receiving' => fn () => $user->can('inventory.view') || $user->can('qc.view') ? $this->dashboard->receiving($days) : null,
            'output' => fn () => $user->can('production.view') ? $this->dashboard->output() : null,
            'inProduction' => fn () => $user->can('production.view') ? $this->dashboard->inProduction() : null,
            'upcoming' => fn () => $user->can('planning.view') || $user->can('purchase.view') ? $this->dashboard->upcoming($user) : null,

            'recentActivity' => fn () => $user->can('audit.view') ? $this->recentActivity() : null,
            'quickActions' => [
                'plan' => $user->can('planning.create'),
                'receive' => $user->can('purchase.receive'),
                'qc' => $user->can('qc.view'),
                'formulas' => $user->can('formula.view'),
            ],
        ]);
    }

    /**
     * The last few things that happened, phrased as sentences.
     *
     * The vocabulary comes from AuditAction rather than from the raw stored
     * slug, and the subject is dropped when it is the person who acted — an
     * entry that renders as "Prashant logged in Prashant" tells the reader
     * less than one that simply says Prashant signed in.
     *
     * @return list<array{id: int, actor: string, action: string, subject: string|null, created_at: string|null}>
     */
    private function recentActivity(): array
    {
        return AuditLog::query()
            ->latest('created_at')
            ->limit(8)
            ->get(['id', 'user_id', 'user_name', 'action', 'auditable_label', 'auditable_type', 'auditable_id', 'created_at'])
            ->map(function (AuditLog $entry): array {
                $describesTheActor = $entry->auditable_type === User::class
                    && $entry->user_id !== null
                    && $entry->auditable_id === $entry->user_id;

                return [
                    'id' => $entry->id,
                    'actor' => $entry->user_name ?? 'System',
                    'action' => $entry->action->label(),
                    'subject' => $describesTheActor ? null : $entry->auditable_label,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }
}
