<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\MasterData\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLot>
 */
class InventoryLotFactory extends Factory
{
    protected $model = InventoryLot::class;

    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'batch_number' => 'RM'.fake()->unique()->numerify('######-###'),
            'received_at' => now()->toDateString(),
            'expiry_at' => now()->addYear()->toDateString(),
            'qc_status' => LotQcStatus::Approved,
            'qc_decided_at' => now(),
            'initial_quantity' => '100.000000',
            'unit_cost' => '120.0000',
        ];
    }

    public function pendingQc(): static
    {
        return $this->state(fn () => ['qc_status' => LotQcStatus::Pending, 'qc_decided_at' => null]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['qc_status' => LotQcStatus::Rejected, 'qc_decided_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expiry_at' => now()->subDay()->toDateString()]);
    }

    public function expiringOn(string $date): static
    {
        return $this->state(fn () => ['expiry_at' => $date]);
    }

    public function forItem(Item $item): static
    {
        return $this->state(fn () => ['item_id' => $item->id]);
    }
}
