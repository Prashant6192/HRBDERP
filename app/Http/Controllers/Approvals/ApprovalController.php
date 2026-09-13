<?php

declare(strict_types=1);

namespace App\Http\Controllers\Approvals;

use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalAction;
use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Approvals\Services\ApprovalSignature;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Quality\Models\QcInspection;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Requests waiting on a signature, and the signed record of every decision.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): Response
    {
        Gate::authorize('approval.view');

        $user = $request->user();
        $mine = $this->approvals->pendingFor($user)->map(fn (Approval $a) => $this->present($a, $user));

        $recent = Approval::query()
            ->with(['steps', 'requestedBy:id,name,approving_authority_id', 'actions.user:id,name', 'approvable'])
            ->orderByDesc('requested_at')
            ->limit(50)
            ->get()
            ->map(fn (Approval $a) => $this->present($a, $user));

        return Inertia::render('approvals/index', [
            'mine' => $mine->values()->all(),
            'recent' => $recent->values()->all(),
            'workflows' => collect((array) config('approvals.workflows'))->map(fn (array $w, string $key) => [
                'key' => $key,
                'label' => $w['label'] ?? $key,
                'description' => $w['description'] ?? '',
                'always' => (bool) ($w['always'] ?? false),
            ])->values()->all(),
            'can' => ['act' => $user->can('approval.act')],
        ]);
    }

    public function approve(Request $request, Approval $approval): RedirectResponse
    {
        Gate::authorize('approval.act');

        try {
            $this->approvals->approve($approval, $request->user(), $request->input('comment'));
        } catch (ApprovalException|\InvalidArgumentException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', 'Approved and signed; the operation has been carried out in your name.');
    }

    public function reject(Request $request, Approval $approval): RedirectResponse
    {
        Gate::authorize('approval.act');

        $data = $request->validate(['comment' => ['required', 'string', 'max:1000']]);

        try {
            $this->approvals->reject($approval, $request->user(), $data['comment']);
        } catch (ApprovalException|\InvalidArgumentException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', 'Rejected; the requester has been told.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Approval $approval, $user): array
    {
        $definition = ApprovalService::definition($approval->workflow_key) ?? [];
        $subject = $approval->approvable;
        $label = method_exists($subject, 'auditLabel') ? $subject->auditLabel() : (class_basename((string) $approval->approvable_type).' #'.$approval->approvable_id);
        $href = match (true) {
            $subject instanceof FormulaVersion => route('formulas.show', $subject->formula_id),
            $subject instanceof ManufacturingOrder => route('manufacturing.show', $subject),
            $subject instanceof QcInspection => route('qc.show', $subject),
            default => null,
        };

        return [
            'id' => $approval->id,
            'workflow' => $approval->workflow_key,
            'workflow_label' => $definition['label'] ?? $approval->workflow_key,
            'subject' => $label,
            'href' => $href,
            'status' => $approval->status->value,
            'requested_by' => $approval->requestedBy?->name,
            'requested_at' => $approval->requested_at?->toIso8601String(),
            'note' => $approval->request_note,
            'triggers' => $approval->context['triggers'] ?? [],
            'completed_at' => $approval->completed_at?->toIso8601String(),
            'can_act' => $this->approvals->canAct($approval, $user),
            'actions' => $approval->actions->map(fn (ApprovalAction $a) => [
                'id' => $a->id,
                'user' => $a->user?->name,
                'action' => $a->action,
                'comment' => $a->comment,
                'acted_at' => $a->acted_at?->toIso8601String(),
                'ip' => $a->ip_address,
                'signature' => $a->signature_hash === null ? null : substr($a->signature_hash, 0, 16).'…',
                'verified' => ApprovalSignature::verify($a, $approval->approvable_type, $approval->approvable_id),
            ])->values()->all(),
        ];
    }
}
