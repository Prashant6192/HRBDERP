<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Formulation\Models\Formula;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Planning\Models\MaterialRequest;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The demo database a developer starts from must actually come up.
 */
class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_demo_seed_produces_a_working_day(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Formula::count());
        $this->assertSame(2, MaterialRequest::count());
        $this->assertSame(1, ManufacturingOrder::where('status', ManufacturingOrderStatus::InProgress->value)->count());

        // Seeding again is a no-op for the operations, not a duplicate day.
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(1, Formula::count());
    }
}
