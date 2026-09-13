<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\DTOs;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Where one material stands and where it is heading: what is on hand, what
 * is spoken for, what production will need, how fast it is used, and what
 * to do about it. Every figure is in the item's stock unit.
 *
 * The three questions every screen should answer — what is happening now,
 * what is likely to go wrong next, what should we do — are answered by
 * sentences() in words a purchase executive would use.
 */
final class ItemOutlook
{
    public const ORDER_TODAY = 'order_today';

    public const ORDER_SOON = 'order_soon';

    public const WATCH = 'watch';

    public const OK = 'ok';

    /**
     * @param  array{id: int|null, name: string|null, last_price: string|null, best_price: string|null, best_vendor: string|null, lead_time_days: int|null, deliveries: int, last_delivery_at: string|null}|null  $vendor
     */
    public function __construct(
        public readonly int $itemId,
        public readonly string $code,
        public readonly string $name,
        public readonly string $type,
        public readonly string $unit,
        public readonly BigDecimal $onHand,
        public readonly BigDecimal $reserved,
        public readonly BigDecimal $usable,
        public readonly BigDecimal $inQuarantine,
        public readonly BigDecimal $dailyRate,
        public readonly int $rateDays,
        public readonly BigDecimal $upcomingRequirement,
        public readonly BigDecimal $horizonDemand,
        public readonly BigDecimal $onOrder,
        public readonly ?BigDecimal $safetyStock,
        public readonly ?BigDecimal $reorderLevel,
        public readonly ?BigDecimal $maximumStock,
        public readonly int $leadTimeDays,
        public readonly string $leadTimeSource,
        public readonly ?int $daysOfCover,
        public readonly ?CarbonImmutable $runsOutAt,
        public readonly ?CarbonImmutable $neededBy,
        public readonly BigDecimal $shortfall,
        public readonly BigDecimal $recommendedQuantity,
        public readonly ?CarbonImmutable $orderBy,
        public readonly string $status,
        public readonly ?array $vendor,
        public readonly CarbonImmutable $asOf,
    ) {}

    public function needsOrder(): bool
    {
        return $this->recommendedQuantity->isPositive();
    }

    /**
     * The narrative: one line per fact, in the order a buyer would read them.
     *
     * @return list<string>
     */
    public function sentences(): array
    {
        $q = fn (BigDecimal $v): string => self::format($v).' '.$this->unit;
        $lines = [];

        $lines[] = "{$q($this->onHand)} on hand".($this->inQuarantine->isPositive() ? ", {$q($this->inQuarantine)} of it held in quarantine." : '.');

        if ($this->reserved->isPositive()) {
            $lines[] = "{$q($this->reserved)} already reserved for running batches.";
        }

        $lines[] = "{$q($this->usable)} usable.";

        if ($this->upcomingRequirement->isPositive()) {
            $lines[] = "Upcoming production requires {$q($this->upcomingRequirement)}".($this->neededBy ? ', the first of it by '.$this->neededBy->format('j M').'.' : '.');
        } elseif ($this->dailyRate->isPositive()) {
            $lines[] = "Used at about {$q($this->dailyRate->toScale(3, RoundingMode::HalfUp))} a day over the last {$this->rateDays} days.";
        } else {
            $lines[] = 'No production booked against it and no recent use.';
        }

        if ($this->onOrder->isPositive()) {
            $lines[] = "{$q($this->onOrder)} already requested from purchase and not yet delivered.";
        }

        if ($this->shortfall->isPositive()) {
            $lines[] = "Shortfall: {$q($this->shortfall)}".($this->safetyStock?->isPositive() ? " (keeping {$q($this->safetyStock)} as safety stock)." : '.');
        } elseif ($this->daysOfCover !== null) {
            $lines[] = "Enough for about {$this->daysOfCover} days at the current rate".($this->runsOutAt ? ', running out around '.$this->runsOutAt->format('j M Y').'.' : '.');
        }

        $lead = "Supplier lead time: {$this->leadTimeDays} days";
        $lines[] = $lead.match ($this->leadTimeSource) {
            'vendor' => ' (from recent deliveries).',
            'item' => ' (as set on the material).',
            default => ' (the default; set it on the material or the vendor).',
        };

        if ($this->needsOrder()) {
            $when = match ($this->status) {
                self::ORDER_TODAY => 'today',
                self::ORDER_SOON => 'by '.$this->orderBy?->format('j M'),
                default => 'by '.$this->orderBy?->format('j M Y'),
            };
            $from = $this->vendor && $this->vendor['name'] ? " from {$this->vendor['name']}" : '';
            $price = $this->vendor && $this->vendor['last_price'] !== null ? ' (last price ₹'.number_format(BigDecimal::of($this->vendor['last_price'])->toFloat(), 2).' per '.$this->unit.')' : '';
            $lines[] = "Recommended action: order {$q($this->recommendedQuantity)} {$when}{$from}{$price}.";
        } else {
            $lines[] = 'Recommended action: nothing to order for now.';
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'unit' => $this->unit,
            'on_hand' => (string) $this->onHand,
            'reserved' => (string) $this->reserved,
            'usable' => (string) $this->usable,
            'in_quarantine' => (string) $this->inQuarantine,
            'daily_rate' => (string) $this->dailyRate->toScale(6, RoundingMode::HalfUp),
            'rate_days' => $this->rateDays,
            'upcoming_requirement' => (string) $this->upcomingRequirement,
            'horizon_demand' => (string) $this->horizonDemand,
            'on_order' => (string) $this->onOrder,
            'safety_stock' => $this->safetyStock === null ? null : (string) $this->safetyStock,
            'reorder_level' => $this->reorderLevel === null ? null : (string) $this->reorderLevel,
            'maximum_stock' => $this->maximumStock === null ? null : (string) $this->maximumStock,
            'lead_time_days' => $this->leadTimeDays,
            'lead_time_source' => $this->leadTimeSource,
            'days_of_cover' => $this->daysOfCover,
            'runs_out_at' => $this->runsOutAt?->toDateString(),
            'needed_by' => $this->neededBy?->toDateString(),
            'shortfall' => (string) $this->shortfall,
            'recommended_quantity' => (string) $this->recommendedQuantity,
            'order_by' => $this->orderBy?->toDateString(),
            'status' => $this->status,
            'vendor' => $this->vendor,
            'sentences' => $this->sentences(),
            'as_of' => $this->asOf->toIso8601String(),
        ];
    }

    private static function format(BigDecimal $value, int $scale = 3): string
    {
        $rounded = $value->toScale($scale, RoundingMode::HalfUp);
        $text = rtrim(rtrim((string) $rounded, '0'), '.');
        [$int, $dec] = array_pad(explode('.', $text === '' || $text === '-' ? '0' : $text, 2), 2, null);
        $negative = str_starts_with($int, '-');
        $int = ltrim($int, '-');

        // Indian grouping: 12,34,567
        if (strlen($int) > 3) {
            $last = substr($int, -3);
            $rest = substr($int, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;
            $int = "{$rest},{$last}";
        }

        return ($negative ? '-' : '').$int.($dec !== null && $dec !== '' ? ".{$dec}" : '');
    }
}
