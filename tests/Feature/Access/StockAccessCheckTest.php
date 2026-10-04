<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\MasterData\Models\Product;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\EmployeeAssignmentService;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Shanu cannot add, change or remove stock": the user's page says, facility
 * by facility, whether he can and why not, and a store's page says why its
 * stock buttons are missing.
 */
class StockAccessCheckTest extends TestCase
{
    use RefreshDatabase;

    private Facility $factory;

    private Facility $depot;

    private User $admin;

    private User $shanu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->factory = Facility::factory()->manufacturing()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Rudrapur Manufacturing Facility']);
        $this->depot = Facility::factory()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Paper Market Facility', 'can_manufacture' => false, 'can_dispatch' => true, 'opening_stock_enabled' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(RoleName::SuperAdmin->value);
        $this->shanu = User::factory()->create(['name' => 'Shanu Kumar']);
        $this->shanu->assignRole(RoleName::WarehouseManager->value);
    }

    /**
     * @return list<array{ok: bool, text: string}>
     */
    private function lines(): array
    {
        $lines = [];
        $this->actingAs($this->admin)->get(route('users.show', $this->shanu))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$lines) {
                $lines = $page->toArray()['props']['stockAccess'];
            });

        return $lines;
    }

    private function line(string $facility): array
    {
        return collect($this->lines())->first(fn (array $l) => str_starts_with($l['text'], "{$facility}:")) ?? $this->fail("No line for {$facility}.");
    }

    #[Test]
    public function a_warehouse_manager_at_the_factory_is_told_he_is_not_assigned_to_the_depot(): void
    {
        app(EmployeeAssignmentService::class)->assign($this->shanu, $this->factory, null, ['is_primary' => true], null);

        $lines = $this->lines();
        $this->assertTrue($lines[0]['ok']);
        $this->assertStringContainsString('add opening stock, change and remove opening stock lines', $lines[0]['text']);

        $depot = $this->line('Paper Market Facility');
        $this->assertFalse($depot['ok']);
        $this->assertStringContainsString('not assigned there', $depot['text']);

        // The depot's store says the same, in place of the missing buttons.
        $store = $this->depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        $this->actingAs($this->shanu)->get(route('stores.show', $store))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.opening_stock', false)
            ->where('stock_note', fn ($note) => str_contains((string) $note, 'You are not assigned to Paper Market Facility')));
    }

    #[Test]
    public function at_the_depot_with_opening_stock_open_he_can_add_change_and_remove(): void
    {
        app(EmployeeAssignmentService::class)->assign($this->shanu, $this->depot, null, ['is_primary' => true], null);

        $depot = $this->line('Paper Market Facility');
        $this->assertTrue($depot['ok']);
        $this->assertStringContainsString('lines can be added, changed and removed', $depot['text']);

        $store = $this->depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        $this->actingAs($this->shanu)->get(route('stores.show', $store))->assertInertia(fn (Assert $page) => $page
            ->where('can.opening_stock', true)
            ->where('stock_note', null));
    }

    #[Test]
    public function with_opening_stock_closed_the_page_says_how_to_correct_stock(): void
    {
        $this->depot->forceFill(['opening_stock_enabled' => false])->save();
        app(EmployeeAssignmentService::class)->assign($this->shanu, $this->depot, null, ['is_primary' => true], null);

        $depot = $this->line('Paper Market Facility');
        $this->assertFalse($depot['ok']);
        $this->assertStringContainsString('opening stock entry is closed', $depot['text']);

        $store = $this->depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        $this->actingAs($this->shanu)->get(route('stores.show', $store))->assertInertia(fn (Assert $page) => $page
            ->where('can.opening_stock', false)
            ->where('stock_note', fn ($note) => str_contains((string) $note, 'Opening stock entry is closed at Paper Market Facility')));
    }

    #[Test]
    public function a_role_that_cannot_change_stock_is_named(): void
    {
        $this->shanu->syncRoles([RoleName::Designer->value]);

        $lines = $this->lines();
        $this->assertCount(1, $lines);
        $this->assertFalse($lines[0]['ok']);
    }

    #[Test]
    public function at_the_depot_he_books_changes_and_removes_opening_stock_of_a_product(): void
    {
        app(EmployeeAssignmentService::class)->assign($this->shanu, $this->depot, null, ['is_primary' => true], null);
        $store = $this->depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole();
        $oil = Product::factory()->create(['code' => 'FG-MO-300', 'name' => 'Medicated Oil 300ml', 'stock_uom_id' => Uom::where('code', 'PCS')->sole()->id, 'client_id' => null]);

        $this->actingAs($this->shanu)->get(route('facilities.opening-stock.create', $this->depot))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.correct', true));

        $this->actingAs($this->shanu)->post(route('facilities.opening-stock.store', $this->depot), [
            'warehouse_id' => (string) $store->id,
            'lines' => [['item_id' => (string) $oil->id, 'quantity' => '120', 'batch_number' => 'PM-01', 'expiry_at' => now()->addYear()->toDateString()]],
        ])->assertSessionHasNoErrors()->assertRedirect(route('stores.show', $store));

        $lot = InventoryLot::query()->where('item_id', $oil->id)->sole();
        $onHand = function () use ($oil, $store): string {
            $value = app(StockBalanceService::class)->onHand($oil, $store)->__toString();

            return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
        };
        $this->assertSame('120', $onHand());

        $this->actingAs($this->shanu)->patch(route('facilities.opening-stock.update', [$this->depot, $lot]), [
            'quantity' => '100', 'reason' => 'Counted again: 100, not 120',
        ])->assertSessionHasNoErrors();
        $this->assertSame('100', $onHand());

        $this->actingAs($this->shanu)->delete(route('facilities.opening-stock.destroy', [$this->depot, $lot]), [
            'reason' => 'Booked twice by mistake',
        ])->assertSessionHasNoErrors();
        $this->assertSame('0', $onHand());
    }
}
