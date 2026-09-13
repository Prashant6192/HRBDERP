<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Services;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Exceptions\ApprovalException;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalAction;
use App\Domain\Approvals\Models\ApprovalStep;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Services\ManufacturingOrderService;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Quality\Services\QcInspectionService;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use App\Notifications\ErpAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Maker-checker on the operations that matter.
 *
 * One person raises a request; another authorises it. The checker is the
 * requester's approving authority, or anyone holding the workflow's
 * permission — never the requester. Each decision is recorded as a signed
 * action that is never edited, and when the last step is approved the
 * operation itself is carried out in the checker's name.
 */
class ApprovalService
{
    public function __construct(
        private readonly FormulaService $formulas,
        private readonly ManufacturingOrderService $orders,
        private readonly QcInspectionService $inspections,
    ) {}

    /**
     * The workflow's definition. Keys carry dots ("formula.activate"), so
     * the array is read whole rather than through config()'s dot notation.
     *
     * @return array<string, mixed>|null
     */
    public static function definition(string $workflow): ?array
    {
        $definition = ((array) config('approvals.workflows', []))[$workflow] ?? null;

        return is_array($definition) ? $definition : null;
    }

    /**
     * Does this workflow always need a second signature?
     */
    public static function alwaysRequired(string $workflow): bool
    {
        return (bool) (self::definition($workflow)['always'] ?? false);
    }

    /**
     * Raise a request. If one is already open for the same record and
     * workflow, it is returned rather than duplicated.
     *
     * @param  array<string, mixed>  $context
     */
    public function request(Model $subject, string $workflow, User $maker, array $context = [], ?string $note = null): Approval
    {
        $definition = self::definition($workflow);

        if ($definition === null) {
            throw new ApprovalException("Unknown approval workflow \"{$workflow}\".");
        }

        return DB::transaction(function () use ($subject, $workflow, $maker, $context, $note, $definition): Approval {
            $existing = Approval::query()
                ->where('approvable_type', $subject->getMorphClass())
                ->where('approvable_id', $subject->getKey())
                ->where('workflow_key', $workflow)
                ->open()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $approval = Approval::query()->create([
                'approvable_type' => $subject->getMorphClass(),
                'approvable_id' => $subject->getKey(),
                'workflow_key' => $workflow,
                'status' => ApprovalStatus::Pending,
                'current_step' => 1,
                'requested_by' => $maker->id,
                'requested_at' => now(),
                'request_note' => $note,
                'context' => $context,
            ]);

            $approval->steps()->create([
                'sequence' => 1,
                'name' => $definition['label'] ?? $workflow,
                'required_permission' => $definition['permission'] ?? null,
                'required_role' => $definition['role'] ?? null,
                'require_both' => false,
                'status' => 'pending',
            ]);

            $this->tell($approval, $maker);

            return $approval->fresh(['steps', 'requestedBy']);
        });
    }

    /**
     * Whether a person may decide the current step: the requester's
     * approving authority, or a holder of the step's permission — and
     * never the requester.
     */
    public function canAct(Approval $approval, User $user): bool
    {
        $approval->loadMissing(['steps', 'requestedBy']);
        $step = $approval->currentStep();

        if ($step === null || $approval->status !== ApprovalStatus::Pending) {
            return false;
        }

        if ((int) $approval->requested_by === (int) $user->id && ! $user->isSuperAdmin()) {
            return false;
        }

        $authority = (int) ($approval->requestedBy?->approving_authority_id ?? 0);

        if ($authority === (int) $user->id) {
            return true;
        }

        return $step->isActionableBy($user);
    }

    public function approve(Approval $approval, User $checker, ?string $comment = null): Approval
    {
        return $this->decide($approval, $checker, 'approved', $comment);
    }

    public function reject(Approval $approval, User $checker, string $comment): Approval
    {
        return $this->decide($approval, $checker, 'rejected', $comment);
    }

    /**
     * Requests awaiting this person's decision.
     *
     * @return Collection<int, Approval>
     */
    public function pendingFor(User $user): Collection
    {
        return Approval::query()
            ->where('status', ApprovalStatus::Pending->value)
            ->with(['steps', 'requestedBy:id,name,approving_authority_id', 'approvable'])
            ->orderBy('requested_at')
            ->get()
            ->filter(fn (Approval $a) => $this->canAct($a, $user))
            ->values();
    }

    private function decide(Approval $approval, User $checker, string $decision, ?string $comment): Approval
    {
        return DB::transaction(function () use ($approval, $checker, $decision, $comment): Approval {
            $approval = Approval::query()->lockForUpdate()->with(['steps', 'requestedBy'])->findOrFail($approval->getKey());

            if ($approval->status !== ApprovalStatus::Pending) {
                throw new ApprovalException("This request is already {$approval->status->value}.");
            }

            if ((int) $approval->requested_by === (int) $checker->id) {
                throw new ApprovalException('The person who raised a request cannot approve it. Maker and checker must differ.');
            }

            if (! $this->canAct($approval, $checker)) {
                throw new ApprovalException('You are not this request\'s approving authority and do not hold the permission its step requires.');
            }

            if ($decision === 'rejected' && trim((string) $comment) === '') {
                throw new ApprovalException('Say why it is rejected.');
            }

            /** @var ApprovalStep $step */
            $step = $approval->currentStep();

            // Signed before it is written, and never written again.
            $action = new ApprovalAction([
                'approval_id' => $approval->id,
                'approval_step_id' => $step->id,
                'user_id' => $checker->id,
                'action' => $decision,
                'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
                'ip_address' => Request::ip(),
                'user_agent' => mb_substr((string) Request::userAgent(), 0, 255),
                'acted_at' => now(),
            ]);
            $action->signature_hash = ApprovalSignature::sign($action, $approval->approvable_type, $approval->approvable_id);
            $action->save();

            $step->fill(['status' => $decision, 'acted_by' => $checker->id, 'acted_at' => now()])->save();

            if ($decision === 'rejected') {
                $approval->fill(['status' => ApprovalStatus::Rejected, 'completed_at' => now()])->save();
                $this->tellRequester($approval, "Rejected: {$approval->steps->first()?->name}", $comment ?? '');

                return $approval->fresh(['steps', 'actions']);
            }

            $next = $approval->steps->firstWhere('sequence', $approval->current_step + 1);

            if ($next !== null) {
                $approval->fill(['current_step' => $next->sequence])->save();

                return $approval->fresh(['steps', 'actions']);
            }

            $approval->fill(['status' => ApprovalStatus::Approved, 'completed_at' => now()])->save();
            $this->carryOut($approval, $checker);
            $this->tellRequester($approval, "Approved: {$step->name}", $comment ?? 'Signed off.');

            return $approval->fresh(['steps', 'actions']);
        });
    }

    /**
     * The operation the request was about, done in the checker's name.
     */
    private function carryOut(Approval $approval, User $checker): void
    {
        $subject = $approval->approvable;
        $context = is_array($approval->context) ? $approval->context : [];

        match ($approval->workflow_key) {
            'formula.activate' => $subject instanceof FormulaVersion ? $this->formulas->activate($subject, $checker->id) : null,
            'production.release' => $subject instanceof ManufacturingOrder ? $this->orders->approve($subject, $checker->id) : null,
            'qc.override' => $subject instanceof QcInspection ? $this->inspections->approve(
                $subject,
                $checker->id,
                $context['remarks'] ?? 'Released on override approval.',
                $context['parameters'] ?? null,
                isset($context['destination_warehouse_id']) ? Warehouse::query()->find($context['destination_warehouse_id']) : null,
                override: true,
            ) : null,
            default => null,
        };
    }

    private function tell(Approval $approval, User $maker): void
    {
        $definition = self::definition($approval->workflow_key) ?? [];
        $label = $definition['label'] ?? $approval->workflow_key;
        $recipients = collect();

        if ($maker->approving_authority_id !== null) {
            $recipients = User::query()->whereKey($maker->approving_authority_id)->get();
        }

        if ($recipients->isEmpty() && isset($definition['permission'])) {
            $recipients = User::query()
                ->permission($definition['permission'])
                ->where('status', 'active')
                ->whereKeyNot($maker->id)
                ->limit(10)
                ->get();
        }

        foreach ($recipients as $user) {
            $user->notify(new ErpAlert(
                title: "{$label} needs your signature",
                body: "{$maker->name} raised it".($approval->request_note ? ": {$approval->request_note}" : '.'),
                href: route('approvals.index'),
                severity: 'medium',
                category: 'approval',
                key: "approval:{$approval->id}",
            ));
        }
    }

    private function tellRequester(Approval $approval, string $title, string $body): void
    {
        $approval->requestedBy?->notify(new ErpAlert(
            title: $title,
            body: $body,
            href: route('approvals.index'),
            severity: 'low',
            category: 'approval',
            key: "approval:{$approval->id}:done",
        ));
    }
}
