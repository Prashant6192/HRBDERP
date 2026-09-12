<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\InventoryReservationService;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Warehousing\Models\Warehouse;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Two factory users reserve the last of a material at the same moment.
 *
 * This forks real processes with their own database connections and lets
 * them race. A single-threaded test cannot show that the row lock works; it
 * can only show that the code compiles.
 *
 * DatabaseTruncation rather than RefreshDatabase: the latter wraps the test
 * in a transaction nobody else can see, and the children need committed rows.
 */
class ConcurrentReservationTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * The migration-seeded reference rows must outlive the truncation.
     *
     * @var list<string>
     */
    protected array $exceptTables = ['migrations', 'facility_types', 'store_categories'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are required to fork competing processes.');
        }

        $this->seed(UomSeeder::class);
    }

    /**
     * Truncation normally happens only on the way in. Rows this class commits
     * would otherwise still be there when the next, transaction-wrapped test
     * class starts, and be counted by it.
     */
    protected function tearDown(): void
    {
        DB::reconnect();
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    #[Test]
    public function two_simultaneous_reservations_for_the_last_stock_cannot_both_succeed(): void
    {
        $item = RawMaterial::factory()->create(['code' => 'RM-RACE']);
        $store = Warehouse::factory()->create(['code' => 'WH-RACE']);
        $lot = InventoryLot::factory()->forItem($item)->create();

        app(InventoryLedgerService::class)->receive($item, $store, '10', $lot);

        $results = $this->race(
            workers: 2,
            work: function (int $worker) use ($item, $store): string {
                try {
                    app(InventoryReservationService::class)->reserve($item, $item, $store, '8');

                    return 'reserved';
                } catch (InsufficientStockException) {
                    return 'refused';
                }
            },
        );

        sort($results);
        $this->assertSame(['refused', 'reserved'], $results, 'Exactly one of the two must win.');

        $balance = StockBalance::query()->where('item_id', $item->id)->where('warehouse_id', $store->id)->sole();
        $this->assertSame('10.000000', $balance->on_hand);
        $this->assertSame('8.000000', $balance->reserved, 'Only the winner\'s hold may exist.');
    }

    #[Test]
    public function many_competing_receipts_to_one_position_lose_nothing(): void
    {
        $item = RawMaterial::factory()->create(['code' => 'RM-MANY']);
        $store = Warehouse::factory()->create(['code' => 'WH-MANY']);
        $lot = InventoryLot::factory()->forItem($item)->create();

        // Four processes each post three receipts of 1 to the same balance.
        // If the lock were missing, lost updates would leave fewer than 12.
        $results = $this->race(
            workers: 4,
            work: function (int $worker) use ($item, $store, $lot): string {
                $ledger = app(InventoryLedgerService::class);

                for ($i = 0; $i < 3; $i++) {
                    $ledger->receive($item, $store, '1', $lot);
                }

                return 'done';
            },
        );

        $this->assertSame(['done', 'done', 'done', 'done'], $results);

        $balance = StockBalance::query()->where('item_id', $item->id)->where('warehouse_id', $store->id)->sole();
        $this->assertSame('12.000000', $balance->on_hand);
    }

    /**
     * Fork N workers, run the callback in each after a common start time, and
     * collect what each returned.
     *
     * @param  callable(int): string  $work
     * @return list<string>
     */
    private function race(int $workers, callable $work): array
    {
        $dir = sys_get_temp_dir().'/hrbderp-race-'.uniqid();
        mkdir($dir);

        // No connection may be open at fork time, or the children would share
        // its socket and the first one to close it would sever the others.
        DB::disconnect();

        $startAt = microtime(true) + 0.5;
        $pids = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork a worker process.');
            }

            if ($pid === 0) {
                // Child.
                DB::purge();
                DB::reconnect();

                while (microtime(true) < $startAt) {
                    usleep(1000);
                }

                try {
                    $outcome = $work($worker);
                } catch (Throwable $e) {
                    $outcome = 'error: '.get_class($e).': '.$e->getMessage();
                }

                file_put_contents("{$dir}/{$worker}", $outcome);

                // Die without running PHPUnit's shutdown in the child.
                posix_kill(posix_getpid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();

        $results = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $results[] = file_exists("{$dir}/{$worker}")
                ? (string) file_get_contents("{$dir}/{$worker}")
                : 'no result';
        }

        return $results;
    }
}
