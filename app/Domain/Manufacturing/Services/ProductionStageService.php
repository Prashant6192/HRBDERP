<?php

declare(strict_types=1);

namespace App\Domain\Manufacturing\Services;

use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Enums\ProductionStage;
use App\Domain\Manufacturing\Exceptions\ManufacturingException;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderStageEvent;
use Illuminate\Support\Facades\DB;

/**
 * Real-time batch progress. Instead of "in production", the floor records
 * exact stages — weighing completed, charging 40%, mixing completed,
 * cooling, in-process QC, packaging 65% — and management sees them.
 */
class ProductionStageService
{
    public function record(ManufacturingOrder $order, ProductionStage $stage, int $progress, ?string $note, ?int $userId): ManufacturingOrder
    {
        return DB::transaction(function () use ($order, $stage, $progress, $note, $userId): ManufacturingOrder {
            $order = ManufacturingOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($order->status !== ManufacturingOrderStatus::InProgress) {
                throw new ManufacturingException("{$order->number} is {$order->status->label()}; stages are recorded on a batch in progress.");
            }

            if ($stage === ProductionStage::Completed) {
                throw new ManufacturingException('Completion is recorded by completing the batch, with its output.');
            }

            $progress = max(0, min(100, $progress));

            $order->stageEvents()->create([
                'stage' => $stage,
                'progress' => $progress,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'recorded_by' => $userId,
                'recorded_at' => now(),
            ]);

            $order->fill(['current_stage' => $stage, 'stage_progress' => $progress, 'stage_updated_at' => now()])->save();

            return $order->refresh();
        });
    }

    /**
     * Where the batch stands, for a screen: the stage, its progress, the
     * overall progress, and the trail of readings.
     *
     * @return array{stage: string|null, stage_label: string|null, progress: int, overall: int, updated_at: string|null, stages: list<array{value: string, label: string, order: int}>, events: list<array<string, mixed>>}
     */
    public function summary(ManufacturingOrder $order): array
    {
        $stage = $order->current_stage;

        $events = $order->stageEvents()
            ->with('recordedBy:id,name')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (ManufacturingOrderStageEvent $e) => [
                'id' => $e->id,
                'stage' => $e->stage->value,
                'stage_label' => $e->stage->label(),
                'progress' => $e->progress,
                'note' => $e->note,
                'by' => $e->recordedBy?->name,
                'at' => $e->recorded_at->toIso8601String(),
            ])
            ->all();

        return [
            'stage' => $stage?->value,
            'stage_label' => $stage?->label(),
            'progress' => (int) $order->stage_progress,
            'overall' => $stage === null ? ($order->status === ManufacturingOrderStatus::Completed ? 100 : 0) : $stage->overallProgress((int) $order->stage_progress),
            'updated_at' => $order->stage_updated_at?->toIso8601String(),
            'stages' => ProductionStage::options(),
            'events' => $events,
        ];
    }

    /**
     * A short phrase for lists: "Mixing · 60%".
     */
    public static function phrase(ManufacturingOrder $order): ?string
    {
        $stage = $order->current_stage;

        if ($stage === null) {
            return null;
        }

        if ($stage === ProductionStage::Completed) {
            return 'Completed';
        }

        return $stage->label().' · '.(int) $order->stage_progress.'%';
    }
}
