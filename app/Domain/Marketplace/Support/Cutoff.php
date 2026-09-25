<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The time of day by which every label printed that day should be packed.
 */
final class Cutoff
{
    public static function timezone(): string
    {
        return (string) config('erp.company.timezone', 'Asia/Kolkata');
    }

    /**
     * Today, where the depot is.
     */
    public static function today(?CarbonInterface $now = null): CarbonImmutable
    {
        return CarbonImmutable::instance($now ?? now())->timezone(self::timezone())->startOfDay();
    }

    /**
     * The cut-off on a given day, as an instant.
     */
    public static function on(CarbonInterface|string $day): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', array_pad(explode(':', (string) config('erp.online_orders.cutoff', '16:00')), 2, 0));

        $date = $day instanceof CarbonInterface ? $day->format('Y-m-d') : substr($day, 0, 10);

        return CarbonImmutable::parse($date, self::timezone())->setTime($hour, $minute);
    }

    public static function passed(CarbonInterface|string $day, ?CarbonInterface $now = null): bool
    {
        return CarbonImmutable::instance($now ?? now())->greaterThanOrEqualTo(self::on($day));
    }

    public static function label(): string
    {
        return self::on(self::today())->format('g:i A');
    }
}
