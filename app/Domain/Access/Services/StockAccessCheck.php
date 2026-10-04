<?php

declare(strict_types=1);

namespace App\Domain\Access\Services;

use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Models\User;

/**
 * "Why can't Shanu change the stock?" — answered on the user's page, in
 * plain words, from the same rules the store and opening stock screens
 * use: the role's permissions, the facilities the person is assigned to,
 * and whether each facility still takes opening stock.
 */
final class StockAccessCheck
{
    public function __construct(private readonly FacilityAccess $facilities) {}

    /**
     * @return list<array{ok: bool, text: string}>
     */
    public function for(User $user): array
    {
        $first = strtok($user->name, ' ') ?: $user->name;

        if (! $user->can('inventory.view')) {
            return [['ok' => false, 'text' => "{$first} cannot work with stock: none of their roles allows it. Give them the Warehouse Manager role."]];
        }

        $abilities = collect([
            'receive stock' => $user->can('inventory.receive') || $user->can('purchase.receive'),
            'transfer stock' => $user->can('inventory.transfer'),
            'count stock' => $user->can('inventory.count'),
            'add opening stock' => $user->can('inventory.opening_stock'),
            'change and remove opening stock lines' => $user->can('inventory.opening_stock') && $user->can('inventory.reverse'),
        ])->filter()->keys();

        $lines = [[
            'ok' => $abilities->isNotEmpty(),
            'text' => $abilities->isEmpty()
                ? "{$first} can only look at stock: none of their roles allows changing it."
                : "{$first}'s role allows them to ".$abilities->implode(', ').'.',
        ]];

        if ($abilities->isEmpty()) {
            return $lines;
        }

        $ids = $this->facilities->facilityIds($user);

        foreach (Facility::query()->active()->ordered()->get(['id', 'name', 'opening_stock_enabled']) as $facility) {
            if ($ids !== null && ! in_array($facility->id, $ids, true)) {
                $lines[] = ['ok' => false, 'text' => "{$facility->name}: {$first} cannot change its stock — they are not assigned there. Add {$facility->name} under Facility assignments below."];

                continue;
            }

            $lines[] = ! $user->can('inventory.opening_stock')
                ? ['ok' => true, 'text' => "{$facility->name}: {$first} can change its stock."]
                : ($facility->opening_stock_enabled
                    ? ['ok' => true, 'text' => "{$facility->name}: {$first} can change its stock, and opening stock is open — lines can be added, changed and removed."]
                    : ['ok' => false, 'text' => "{$facility->name}: opening stock entry is closed, so lines cannot be added, changed or removed. Re-open it on the facility's Settings tab, or correct quantities with a stock count."]);
        }

        return $lines;
    }
}
