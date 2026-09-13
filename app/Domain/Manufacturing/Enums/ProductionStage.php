<?php

declare(strict_types=1);

namespace App\Domain\Manufacturing\Enums;

/**
 * Where a batch is on the floor, in the order the work happens. "In
 * production" is not a stage; these are.
 */
enum ProductionStage: string
{
    case Weighing = 'weighing';
    case Charging = 'charging';
    case Mixing = 'mixing';
    case Heating = 'heating';
    case Cooling = 'cooling';
    case QcPending = 'qc_pending';
    case Filling = 'filling';
    case Packaging = 'packaging';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Weighing => 'Weighing',
            self::Charging => 'Charging',
            self::Mixing => 'Mixing',
            self::Heating => 'Heating',
            self::Cooling => 'Cooling',
            self::QcPending => 'In-process QC',
            self::Filling => 'Filling',
            self::Packaging => 'Packaging',
            self::Completed => 'Completed',
        };
    }

    /**
     * Position in the sequence, 1-based, for a stepper.
     */
    public function order(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    /**
     * How far through the whole batch a stage at a given progress is: each
     * stage before Completed is an equal slice of the bar.
     */
    public function overallProgress(int $progress): int
    {
        if ($this === self::Completed) {
            return 100;
        }

        $slices = count(self::cases()) - 1;
        $done = $this->order() - 1;

        return (int) round((($done * 100) + max(0, min(100, $progress))) / $slices);
    }

    /**
     * @return list<array{value: string, label: string, order: int}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label(), 'order' => $s->order()], self::cases());
    }
}
