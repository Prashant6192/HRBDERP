<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\EmployeeAssignmentService;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeLabelReader;
use Tests\Support\LabelFixtures;
use Tests\TestCase;

/**
 * The user's page says why a depot manager does or does not see today's
 * labels.
 */
class OnlineOrdersAccessCheckTest extends TestCase
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
        Storage::fake('files');
        $this->app->instance(AiLabelReader::class, new FakeLabelReader(available: false));
        config(['erp.company.timezone' => 'Asia/Kolkata']);

        $this->factory = Facility::factory()->manufacturing()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Rudrapur Manufacturing Facility']);
        $this->depot = Facility::factory()->withStores([WarehouseType::FinishedGoods])->create(['name' => 'Paper Market Warehouse', 'can_manufacture' => false, 'can_dispatch' => true]);
        $this->seed(ReferenceDataSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(RoleName::SuperAdmin->value);
        $this->shanu = User::factory()->create(['name' => 'Shanu Kumar']);
        $this->shanu->assignRole(RoleName::WarehouseManager->value);

        $agency = User::factory()->create();
        $agency->assignRole(RoleName::EcommerceAgency->value);
        app(OnlineOrderService::class)->upload(
            Brand::query()->where('code', 'RR')->sole(),
            Marketplace::query()->where('code', 'MEESHO')->sole(),
            $this->depot->stores()->where('type', WarehouseType::FinishedGoods->value)->sole(),
            [LabelFixtures::meesho([['awb' => 'VL0000000000001', 'courier' => 'Valmo', 'payment' => 'cod', 'sku' => 'Oil', 'qty' => 1, 'order' => '200000000000001', 'invoice' => 'abcde0001', 'total' => '199.00', 'name' => 'A', 'state' => 'Delhi']])],
            $agency,
        );
    }

    private function lines(): array
    {
        $lines = [];
        $this->actingAs($this->admin)->get(route('users.show', $this->shanu))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$lines) {
                $lines = $page->toArray()['props']['onlineOrdersAccess'];
            });

        return $lines;
    }

    #[Test]
    public function assigned_to_the_factory_the_depot_manager_still_works_the_depots_labels(): void
    {
        app(EmployeeAssignmentService::class)->assign($this->shanu, $this->factory, null, ['is_primary' => true], null);

        $lines = $this->lines();

        $this->assertTrue(collect($lines)->every(fn ($l) => $l['ok']));
        $this->assertStringContainsString('Shanu can open Online orders and print, pack, hand over', $lines[0]['text']);
        $this->assertStringContainsString("so works every facility's labels", $lines[1]['text']);
        $this->assertStringContainsString('are all visible to Shanu', $lines[2]['text']);
    }

    #[Test]
    public function someone_who_only_looks_is_held_to_their_facility_and_the_page_says_so(): void
    {
        $this->shanu->syncRoles([RoleName::MarketingManager->value]);
        app(EmployeeAssignmentService::class)->assign($this->shanu, $this->factory, null, ['is_primary' => true], null);

        $lines = $this->lines();

        $this->assertStringContainsString('(view only)', $lines[0]['text']);
        $this->assertSame('Shanu sees the labels of: Rudrapur Manufacturing Facility.', $lines[1]['text']);
        $this->assertFalse($lines[2]['ok']);
        $this->assertStringContainsString('Shanu cannot see 1 parcel(s) at Paper Market Warehouse', $lines[2]['text']);
    }

    #[Test]
    public function assigned_to_the_depot_every_label_is_visible(): void
    {
        app(EmployeeAssignmentService::class)->assign($this->shanu, $this->depot, null, ['is_primary' => true], null);

        $lines = $this->lines();

        $this->assertTrue(collect($lines)->every(fn ($l) => $l['ok']));
        $this->assertStringContainsString('are all visible to Shanu', $lines[2]['text']);
    }

    #[Test]
    public function a_role_without_online_orders_is_named(): void
    {
        $this->shanu->syncRoles([RoleName::Designer->value]);

        $lines = $this->lines();

        $this->assertCount(1, $lines);
        $this->assertFalse($lines[0]['ok']);
        $this->assertStringStartsWith('Shanu cannot open Online orders', $lines[0]['text']);
    }
}
