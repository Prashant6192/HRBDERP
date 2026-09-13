<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\DTOs;

use Carbon\CarbonImmutable;

/**
 * One thing that is not as it should be: a delayed batch, a slow QC
 * decision, a price that jumped. Management sees these and nothing else.
 *
 * The key is stable for as long as the condition lasts, so the escalation
 * engine can tell a standing exception from a new one.
 */
final class FactoryException
{
    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    public const LOW = 'low';

    /**
     * @param  array<string, mixed>  $metrics
     */
    public function __construct(
        public readonly string $rule,
        public readonly string $subject,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $detail,
        public readonly ?string $href,
        public readonly CarbonImmutable $since,
        public readonly array $metrics = [],
        public readonly ?int $facilityId = null,
    ) {}

    public function key(): string
    {
        return "{$this->rule}:{$this->subject}";
    }

    public function ageHours(?CarbonImmutable $asOf = null): float
    {
        return round($this->since->diffInMinutes($asOf ?? CarbonImmutable::now(), true) / 60, 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?CarbonImmutable $asOf = null): array
    {
        return [
            'key' => $this->key(),
            'rule' => $this->rule,
            'rule_label' => self::label($this->rule),
            'subject' => $this->subject,
            'severity' => $this->severity,
            'title' => $this->title,
            'detail' => $this->detail,
            'href' => $this->href,
            'since' => $this->since->toIso8601String(),
            'age_hours' => $this->ageHours($asOf),
            'metrics' => $this->metrics,
            'facility_id' => $this->facilityId,
        ];
    }

    public static function label(string $rule): string
    {
        return match ($rule) {
            'slow_qc' => 'Slow QC',
            'delayed_batch' => 'Delayed batch',
            'overdue_plan' => 'Plan not started',
            'pmr_overdue' => 'Material request unfilled',
            'stockout_imminent' => 'Could stop production',
            'late_transfer' => 'Transfer late',
            'stock_discrepancy' => 'Stock discrepancy',
            'price_increase' => 'Unexpected price increase',
            'high_rejection' => 'Unusually high rejection',
            'production_below_target' => 'Production below target',
            'material_variance' => 'High material variance',
            'abnormal_wastage' => 'Abnormal wastage',
            default => ucfirst(str_replace('_', ' ', $rule)),
        };
    }
}
