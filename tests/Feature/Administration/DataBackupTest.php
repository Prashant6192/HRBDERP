<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Administration\Services\DataBackupService;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Issue #19: the whole ERP as one file the administrator downloads, and
 * the whole ERP back from it — old entries, employees, stores and the
 * stock history included.
 */
class DataBackupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private DataBackupService $backup;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
        config(['erp.company.name' => 'HRBD']);

        $this->admin = User::factory()->create(['name' => 'Prashant']);
        $this->admin->assignRole(RoleName::SuperAdmin->value);
        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->owner->assignRole(RoleName::Owner->value);
        $this->backup = app(DataBackupService::class);
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir().'/hrbd-test-*.zip') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * @return array{0: Facility, 1: Warehouse, 2: RawMaterial, 3: User}
     */
    private function stockedFactory(): array
    {
        $facility = Facility::factory()->manufacturing()
            ->withStores([WarehouseType::RawMaterial, WarehouseType::Packaging, WarehouseType::FinishedGoods, WarehouseType::Quarantine])
            ->create(['name' => 'Rudrapur Factory']);
        $store = $facility->stores()->where('type', WarehouseType::RawMaterial->value)->firstOrFail();
        // A store whose manager is a person, and a person assigned to the store: the cycle the restore must break.
        $store->forceFill(['manager_id' => $this->owner->id])->save();

        $kg = Uom::where('code', 'KG')->sole();
        $item = RawMaterial::factory()->create(['name' => 'Aloe Vera Gel', 'stock_uom_id' => $kg->id]);
        $lot = InventoryLot::factory()->forItem($item)->create(['batch_number' => 'AV-001']);
        app(InventoryLedgerService::class)->receive($item, $store, '100', $lot, userId: $this->admin->id);
        app(InventoryLedgerService::class)->receive($item, $store, '25', $lot, userId: $this->admin->id);

        $storeKeeper = User::factory()->create(['name' => 'Ramesh (store)']);
        $storeKeeper->assignRole(RoleName::StoreExecutive->value);
        $storeKeeper->assignments()->create(['facility_id' => $facility->id, 'store_id' => $store->id, 'assigned_by' => $this->admin->id]);

        Vendor::factory()->create(['name' => 'Old Supplier']);
        Storage::disk('local')->put('goods-receipts/bill-001.pdf', '%PDF-1.4 bill');

        return [$facility, $store, $item, $storeKeeper];
    }

    #[Test]
    public function the_backup_holds_every_table_and_every_upload(): void
    {
        $this->stockedFactory();

        $path = $this->backup->export(tempnam(sys_get_temp_dir(), 'hrbd-test-').'.zip');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame('hrbderp', $manifest['app']);
        $this->assertSame('HRBD', $manifest['company']);
        $this->assertSame(3, $manifest['tables']['users']);
        $this->assertSame(2, $manifest['tables']['inventory_transactions']);
        $this->assertArrayHasKey('audit_logs', $manifest['tables']);
        $this->assertArrayNotHasKey('sessions', $manifest['tables'], 'Framework housekeeping stays out');
        $this->assertSame(1, $manifest['files']);
        $this->assertSame('%PDF-1.4 bill', $zip->getFromName('storage/goods-receipts/bill-001.pdf'));
        $this->assertNotEmpty($manifest['migrations']);

        $users = json_decode((string) $zip->getFromName('tables/users.json'), true);
        $this->assertSame('Prashant', $users[0]['name']);
        $zip->close();

        $inspection = $this->backup->inspect($path);
        $this->assertSame([], $inspection['newer_migrations']);
        $this->assertSame([], $inspection['unknown_tables']);
    }

    #[Test]
    public function a_restore_brings_back_old_entries_employees_stores_and_the_stock_history(): void
    {
        [$facility, $store, $item, $storeKeeper] = $this->stockedFactory();
        $auditBefore = DB::table('audit_logs')->count();

        $path = $this->backup->export(tempnam(sys_get_temp_dir(), 'hrbd-test-').'.zip');

        // Life goes on after the backup — and then it all has to come back.
        Vendor::factory()->create(['name' => 'New Supplier']);
        $item->forceFill(['name' => 'Renamed Gel'])->save();
        app(InventoryLedgerService::class)->issue($item, $store, '30', $item->lots()->first(), userId: $this->admin->id);
        $storeKeeper->delete();
        $late = User::factory()->create(['name' => 'Joined later']);
        Storage::disk('local')->delete('goods-receipts/bill-001.pdf');
        $this->assertTrue(app(StockBalanceService::class)->onHand($item, $store)->isEqualTo('95'));

        $this->actingAs($this->admin)->post(route('administration.data.restore'), [
            'backup' => new UploadedFile($path, 'hrbd-erp-backup.zip', 'application/zip', null, true),
            'confirmation' => 'HRBD',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Old entries are back, newer ones are gone.
        $this->assertNull(Vendor::query()->where('name', 'New Supplier')->first());
        $this->assertNotNull(Vendor::query()->where('name', 'Old Supplier')->first());
        $this->assertSame('Aloe Vera Gel', $item->fresh()->name);
        $this->assertSame(2, InventoryTransaction::query()->count(), 'The stock history is as it was');
        $this->assertTrue(app(StockBalanceService::class)->onHand($item->fresh(), $store->fresh())->isEqualTo('125'));

        // Employees, their roles and their store assignments.
        $keeper = User::query()->where('name', 'Ramesh (store)')->firstOrFail();
        $this->assertTrue($keeper->hasRole(RoleName::StoreExecutive->value));
        $this->assertSame($store->id, $keeper->assignments()->sole()->store_id);
        $this->assertNull(User::query()->find($late->id));
        $this->assertTrue($this->admin->fresh()->isSuperAdmin(), 'The administrator restoring is untouched');
        $this->assertSame($this->owner->id, $store->fresh()->manager_id, 'The store ↔ person cycle was put back');
        $this->assertSame('Rudrapur Factory', $facility->fresh()->name);

        // Uploads, the audit trail, and a copy of what was there before.
        Storage::disk('local')->assertExists('goods-receipts/bill-001.pdf');
        $this->assertGreaterThanOrEqual($auditBefore, DB::table('audit_logs')->count(), 'The audit trail only grows');
        $this->assertCount(1, Storage::disk('local')->files('backups'));

        // New records number on from the restored ones, not over them.
        $next = Vendor::factory()->create(['name' => 'After restore']);
        $this->assertGreaterThan(Vendor::query()->where('name', 'Old Supplier')->value('id'), $next->id);

        $this->actingAs($this->admin)->get(route('administration.data'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('copies', 1)->has('uploadLimit'));
    }

    #[Test]
    public function only_the_administrator_may_download_or_restore_and_only_from_a_real_backup(): void
    {
        $this->actingAs($this->owner)->get(route('administration.data.download'))->assertForbidden();
        $this->actingAs($this->owner)->post(route('administration.data.restore'), [])->assertForbidden();

        $this->actingAs($this->admin)->get(route('administration.data.download'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertDownload();

        $stray = tempnam(sys_get_temp_dir(), 'hrbd-test-').'.zip';
        $zip = new ZipArchive;
        $zip->open($stray, ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'not a backup');
        $zip->close();

        $this->actingAs($this->admin)->post(route('administration.data.restore'), [
            'backup' => new UploadedFile($stray, 'stray.zip', 'application/zip', null, true),
            'confirmation' => 'HRBD',
        ])->assertSessionHasErrors('backup');
        $this->assertStringContainsString('not an ERP backup', session('errors')->first('backup'));

        $this->actingAs($this->admin)->post(route('administration.data.restore'), [
            'backup' => new UploadedFile($stray, 'stray.zip', 'application/zip', null, true),
            'confirmation' => 'wrong',
        ])->assertSessionHasErrors('confirmation');
    }

    #[Test]
    public function the_console_backs_up_and_restores_too(): void
    {
        $this->stockedFactory();
        $dir = sys_get_temp_dir();

        $this->artisan('erp:backup', ['--to' => $dir])->assertSuccessful();
        $files = glob($dir.'/hrbd-erp-backup-*.zip');
        $this->assertNotEmpty($files);
        $file = end($files);
        rename($file, $renamed = $dir.'/hrbd-test-console.zip');

        Vendor::factory()->create(['name' => 'New Supplier']);

        $this->artisan('erp:restore', ['file' => $renamed, '--force' => true, '--no-copy' => true])
            ->expectsOutputToContain('restored')
            ->assertSuccessful();

        $this->assertNull(Vendor::query()->where('name', 'New Supplier')->first());
    }
}
