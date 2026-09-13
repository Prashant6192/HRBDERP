<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Services;

use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Measurement\Services\UnitConversionService;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Warehousing\Enums\FacilityCapability;
use App\Domain\Warehousing\Models\Facility;
use App\Support\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Plant capacity versus booked production.
 *
 * Each manufacturing facility says how many kilograms of bulk product it
 * can make in a day. Every approved or running batch, and every checked or
 * requested plan, is a booking from its start date for as many days as it
 * needs. From there: utilisation per week, and where a new batch would fit
 * and what it would push.
 */
class CapacityService
{
    public function __construct(private readonly UnitConversionService $conversions) {}

    /**
     * Utilisation per facility per week.
     *
     * @return list<array<string, mixed>>
     */
    public function utilisation(int $weeks = 6, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $start = $asOf->startOfWeek();

        return Facility::query()
            ->active()
            ->withCapability(FacilityCapability::Manufacture)
            ->ordered()
            ->get()
            ->map(function (Facility $facility) use ($weeks, $start, $asOf): array {
                $capacity = $facility->daily_capacity_kg !== null ? BigDecimal::of($facility->daily_capacity_kg) : null;
                $bookings = $this->bookings($facility, $asOf);
                $load = $this->dailyLoad($bookings, $capacity, $start, $start->addWeeks($weeks));

                $weekRows = [];

                for ($w = 0; $w < $weeks; $w++) {
                    $from = $start->addWeeks($w);
                    $to = $from->addDays(6);
                    $booked = BigDecimal::zero();
                    $workingDays = 0;

                    for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
                        if ($d->isSunday()) {
                            continue;
                        }
                        $workingDays++;
                        $booked = $booked->plus($load[$d->toDateString()] ?? BigDecimal::zero());
                    }

                    $weekCapacity = $capacity?->multipliedBy($workingDays);
                    $percent = $weekCapacity !== null && $weekCapacity->isPositive() ? $booked->multipliedBy(100)->dividedBy($weekCapacity, 0, RoundingMode::HalfUp)->toInt() : null;

                    $weekRows[] = [
                        'week_start' => $from->toDateString(),
                        'label' => $from->format('j M').' – '.$to->format('j M'),
                        'capacity_kg' => $weekCapacity === null ? null : Decimal::strip($weekCapacity),
                        'booked_kg' => Decimal::strip($booked),
                        'utilisation_percent' => $percent,
                        'level' => match (true) {
                            $percent === null => 'unknown',
                            $percent > 100 => 'over',
                            $percent >= 85 => 'tight',
                            $percent >= 50 => 'busy',
                            default => 'open',
                        },
                    ];
                }

                return [
                    'facility_id' => $facility->id,
                    'code' => $facility->code,
                    'name' => $facility->name,
                    'daily_capacity_kg' => $capacity === null ? null : Decimal::strip($capacity),
                    'weeks' => $weekRows,
                    'bookings' => $bookings->map(fn (array $b) => [
                        ...$b,
                        'kg' => Decimal::strip($b['kg']),
                        'start' => $b['start']->toDateString(),
                        'end' => $b['end']->toDateString(),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Where a new batch of this many kilograms would fit at a facility from
     * a date, and which bookings it would push.
     *
     * @return array{days_needed: string, start: string, completion: string, capacity_kg: string|null, utilisation_next_week: int|null, pushed: list<array{number: string, label: string, delay_days: int}>, note: string|null}
     */
    public function fit(Facility $facility, BigDecimal|string $kg, ?CarbonImmutable $from = null, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $from = ($from ?? $asOf)->startOfDay();
        $kg = BigDecimal::of($kg);
        $capacity = $facility->daily_capacity_kg !== null ? BigDecimal::of($facility->daily_capacity_kg) : null;

        if ($capacity === null || ! $capacity->isPositive()) {
            return [
                'days_needed' => '1',
                'start' => $from->toDateString(),
                'completion' => $from->toDateString(),
                'capacity_kg' => null,
                'utilisation_next_week' => null,
                'pushed' => [],
                'note' => "{$facility->name} has no daily capacity set, so machine time cannot be worked out. Set it on the facility.",
            ];
        }

        $daysNeeded = $kg->dividedBy($capacity, 6, RoundingMode::HalfUp);
        $bookings = $this->bookings($facility, $asOf);
        $load = $this->dailyLoad($bookings, $capacity, $from, $from->addDays(120));

        // Walk forward filling free capacity day by day.
        $remaining = $kg;
        $day = $from;
        $startedOn = null;
        $guard = 0;

        while ($remaining->isPositive() && $guard++ < 365) {
            if ($day->isSunday()) {
                $day = $day->addDay();

                continue;
            }
            $free = $capacity->minus($load[$day->toDateString()] ?? BigDecimal::zero());

            if ($free->isPositive()) {
                $startedOn ??= $day;
                $remaining = $remaining->minus($free);
            }

            if ($remaining->isPositive()) {
                $day = $day->addDay();
            }
        }

        $completion = $day;
        $startedOn ??= $from;

        // What it pushes: bookings whose window overlaps ours are delayed by
        // as many working days as we take.
        $pushDays = max(1, (int) $daysNeeded->toScale(0, RoundingMode::Ceiling)->toInt());
        $pushed = $bookings
            ->filter(fn (array $b) => $b['start']->lessThanOrEqualTo($completion) && $b['end']->greaterThanOrEqualTo($startedOn) && $b['status'] !== 'in_progress')
            ->map(fn (array $b) => ['number' => $b['number'], 'label' => $b['label'], 'delay_days' => $pushDays])
            ->values()
            ->all();

        $nextWeek = $this->utilisationBetween($load, $capacity, $asOf->addWeek()->startOfWeek(), $asOf->addWeek()->endOfWeek());

        return [
            'days_needed' => Decimal::strip($daysNeeded, 1),
            'start' => $startedOn->toDateString(),
            'completion' => $completion->toDateString(),
            'capacity_kg' => Decimal::strip($capacity),
            'utilisation_next_week' => $nextWeek,
            'pushed' => $pushed,
            'note' => $pushed === [] ? null : count($pushed).' booked batch'.(count($pushed) === 1 ? '' : 'es').' would move by about '.$pushDays.' working day'.($pushDays === 1 ? '' : 's').'.',
        ];
    }

    /**
     * Planned quantity in kilograms, converting through the product's
     * density where the batch is by volume.
     */
    public function toKg(BigDecimal|string $quantity, Uom $uom, ?Product $product = null): BigDecimal
    {
        $kg = Uom::query()->where('code', 'KG')->first();

        if ($kg === null || $uom->is($kg)) {
            return BigDecimal::of($quantity);
        }

        try {
            return $this->conversions->convert($quantity, $uom, $kg, $product);
        } catch (\Throwable) {
            // No bridge between the units: take the figure as it stands.
            return BigDecimal::of($quantity);
        }
    }

    /**
     * Every open booking at a facility with its window.
     *
     * @return Collection<int, array{number: string, label: string, kg: BigDecimal, start: CarbonImmutable, end: CarbonImmutable, status: string, kind: string}>
     */
    private function bookings(Facility $facility, CarbonImmutable $asOf): Collection
    {
        $capacity = $facility->daily_capacity_kg !== null ? BigDecimal::of($facility->daily_capacity_kg) : null;
        $today = $asOf->startOfDay();

        $orders = ManufacturingOrder::query()
            ->where('facility_id', $facility->id)
            ->whereIn('status', [ManufacturingOrderStatus::Approved->value, ManufacturingOrderStatus::InProgress->value])
            ->with(['plannedUom', 'product', 'plan:id,planned_start_date'])
            ->get()
            ->map(function (ManufacturingOrder $o) use ($capacity, $today): array {
                $kg = $this->toKg($o->planned_quantity, $o->plannedUom, $o->product);
                $start = $o->started_at?->startOfDay() ?? ($o->plan?->planned_start_date ? CarbonImmutable::parse($o->plan->planned_start_date) : $today);
                $start = $start->lessThan($today) ? $today : $start;

                return [
                    'number' => $o->number,
                    'label' => ($o->product?->name ?? $o->number).' · '.Decimal::strip($o->planned_quantity).' '.($o->plannedUom?->code ?? ''),
                    'kg' => $kg,
                    'start' => $start,
                    'end' => $start->addDays(max(0, $this->daysFor($kg, $capacity) - 1)),
                    'status' => $o->status->value,
                    'kind' => 'order',
                ];
            });

        $plans = ProductionPlan::query()
            ->where('facility_id', $facility->id)
            ->whereIn('status', [ProductionPlanStatus::Checked->value, ProductionPlanStatus::Requested->value])
            ->with(['plannedUom', 'product'])
            ->get()
            ->map(function (ProductionPlan $p) use ($capacity, $today): array {
                $kg = $this->toKg($p->planned_quantity, $p->plannedUom, $p->product);
                $start = $p->planned_start_date ? CarbonImmutable::parse($p->planned_start_date) : $today;
                $start = $start->lessThan($today) ? $today : $start;

                return [
                    'number' => $p->number,
                    'label' => ($p->product?->name ?? $p->number).' · '.Decimal::strip($p->planned_quantity).' '.($p->plannedUom?->code ?? ''),
                    'kg' => $kg,
                    'start' => $start,
                    'end' => $start->addDays(max(0, $this->daysFor($kg, $capacity) - 1)),
                    'status' => $p->status->value,
                    'kind' => 'plan',
                ];
            });

        return $orders->concat($plans)->sortBy(fn (array $b) => $b['start']->getTimestamp())->values();
    }

    private function daysFor(BigDecimal $kg, ?BigDecimal $capacity): int
    {
        if ($capacity === null || ! $capacity->isPositive()) {
            return 1;
        }

        return max(1, $kg->dividedBy($capacity, 0, RoundingMode::Ceiling)->toInt());
    }

    /**
     * Kilograms booked per day, each booking spread evenly over its window.
     *
     * @param  Collection<int, array{kg: BigDecimal, start: CarbonImmutable, end: CarbonImmutable}>  $bookings
     * @return array<string, BigDecimal>
     */
    private function dailyLoad(Collection $bookings, ?BigDecimal $capacity, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $load = [];

        foreach ($bookings as $b) {
            $days = max(1, (int) $b['start']->diffInDays($b['end'], true) + 1);
            $perDay = $b['kg']->dividedBy($days, 6, RoundingMode::HalfUp);

            for ($d = $b['start']; $d->lessThanOrEqualTo($b['end']); $d = $d->addDay()) {
                if ($d->lessThan($from) || $d->greaterThan($to)) {
                    continue;
                }
                $key = $d->toDateString();
                $load[$key] = ($load[$key] ?? BigDecimal::zero())->plus($perDay);
            }
        }

        return $load;
    }

    /**
     * @param  array<string, BigDecimal>  $load
     */
    private function utilisationBetween(array $load, BigDecimal $capacity, CarbonImmutable $from, CarbonImmutable $to): ?int
    {
        $booked = BigDecimal::zero();
        $days = 0;

        for ($d = $from->startOfDay(); $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            if ($d->isSunday()) {
                continue;
            }
            $days++;
            $booked = $booked->plus($load[$d->toDateString()] ?? BigDecimal::zero());
        }

        $total = $capacity->multipliedBy($days);

        return $total->isPositive() ? $booked->multipliedBy(100)->dividedBy($total, 0, RoundingMode::HalfUp)->toInt() : null;
    }
}
