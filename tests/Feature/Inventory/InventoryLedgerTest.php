<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\DTOs\LedgerLine;
use App\Domain\Inventory\DTOs\LedgerPosting;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\LedgerIntegrityException;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Services\BatchNumberGenerator;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\InventoryReservationService;
use App\Domain\Inventory\Services\SequenceService;
use App\Domain\Inventory\Services\StockAlertService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Warehousing\Models\Warehouse;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The ledger is where an ERP either tells the truth about stock or does not.
 */
class InventoryLedgerTest extends TestCase
{
    use RefreshDatabase;

    private InventoryLedgerService $ledger;

    private StockBalanceService $balances;

    private InventoryReservationService $reservations;

    private RawMaterial $item;

    private Warehouse $store;

    private Warehouse $quarantine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);

        $this->ledger = app(InventoryLedgerService::class);
        $this->balances = app(StockBalanceService::class);
        $this->reservations = app(InventoryReservationService::class);

        $this->item = RawMaterial::factory()->create([
            'code' => 'RM-TEST',
            'reorder_level' => '50',
            'minimum_stock' => '20',
        ]);
        $this->store = Warehouse::factory()->create(['code' => 'WH-RM']);
        $this->quarantine = Warehouse::factory()->quarantine()->create(['code' => 'WH-QA']);
    }

    private function lot(array $attributes = []): InventoryLot
    {
        return InventoryLot::factory()->forItem($this->item)->create($attributes);
    }

    private function receive(string $quantity, ?InventoryLot $lot = null, ?Warehouse $into = null): InventoryTransaction
    {
        return $this->ledger->receive($this->item, $into ?? $this->store, $quantity, $lot);
    }

    // ---- Posting ---------------------------------------------------------

    #[Test]
    public function receiving_stock_raises_the_balance_and_the_ledger_agrees(): void
    {
        $lot = $this->lot();

        $this->receive('100', $lot);
        $this->receive('25.5', $lot);

        $this->assertSame('125.500000', (string) $this->balances->onHand($this->item, $this->store));

        // The cache must equal what the ledger says, not merely be near it.
        $ledgerSum = $lot->balances()->first()->on_hand;
        $this->assertSame('125.500000', $ledgerSum);
    }

    #[Test]
    public function issuing_more_than_is_on_hand_is_refused_and_nothing_is_written(): void
    {
        $lot = $this->lot();
        $this->receive('10', $lot);

        try {
            $this->ledger->issue($this->item, $this->store, '10.000001', $lot);
            $this->fail('Expected the issue to be refused.');
        } catch (InsufficientStockException $e) {
            $this->assertSame('0.000001', (string) $e->shortage);
        }

        // Rolled back: no transaction row, balance untouched.
        $this->assertSame(1, InventoryTransaction::count());
        $this->assertSame('10.000000', (string) $this->balances->onHand($this->item, $this->store));
    }

    #[Test]
    public function a_multi_line_posting_is_all_or_nothing(): void
    {
        $lot = $this->lot();
        $this->receive('10', $lot);

        // First line is fine; second would go negative. Neither may land.
        $posting = new LedgerPosting(
            type: InventoryTransactionType::StockAdjustmentOut,
            warehouseId: $this->store->id,
            lines: [
                new LedgerLine($this->item->id, $this->store->id, '-5', $lot->id),
                new LedgerLine($this->item->id, $this->store->id, '-6', $lot->id),
            ],
        );

        $this->expectException(InsufficientStockException::class);

        try {
            $this->ledger->post($posting);
        } finally {
            $this->assertSame('10.000000', (string) $this->balances->onHand($this->item, $this->store));
            $this->assertSame(1, InventoryTransaction::count());
        }
    }

    #[Test]
    public function a_receipt_cannot_carry_a_negative_line(): void
    {
        $this->expectException(LedgerIntegrityException::class);

        $this->ledger->post(new LedgerPosting(
            type: InventoryTransactionType::GrnReceipt,
            warehouseId: $this->store->id,
            lines: [new LedgerLine($this->item->id, $this->store->id, '-1')],
        ));
    }

    #[Test]
    public function a_zero_quantity_line_is_refused(): void
    {
        $this->expectException(LedgerIntegrityException::class);

        $this->ledger->post(new LedgerPosting(
            type: InventoryTransactionType::GrnReceipt,
            warehouseId: $this->store->id,
            lines: [new LedgerLine($this->item->id, $this->store->id, '0')],
        ));
    }

    #[Test]
    public function a_transfer_moves_stock_between_warehouses_atomically(): void
    {
        $lot = $this->lot();
        $this->receive('40', $lot, $this->quarantine);

        $transaction = $this->ledger->transfer($this->item, $lot, $this->quarantine, $this->store, '40', InventoryTransactionType::QcRelease);

        $this->assertCount(2, $transaction->lines);
        $this->assertSame('0.000000', (string) $this->balances->onHand($this->item, $this->quarantine));
        $this->assertSame('40.000000', (string) $this->balances->onHand($this->item, $this->store));
        // Total stock is unchanged by moving it.
        $this->assertSame('40.000000', (string) $this->balances->onHand($this->item));
    }

    #[Test]
    public function a_transfer_that_does_not_balance_is_refused(): void
    {
        $this->expectException(LedgerIntegrityException::class);

        $this->ledger->post(new LedgerPosting(
            type: InventoryTransactionType::StockTransfer,
            warehouseId: $this->store->id,
            counterpartWarehouseId: $this->quarantine->id,
            lines: [
                new LedgerLine($this->item->id, $this->store->id, '-10'),
                new LedgerLine($this->item->id, $this->quarantine->id, '9'),
            ],
        ));
    }

    #[Test]
    public function ledger_rows_are_immutable(): void
    {
        $transaction = $this->receive('5', $this->lot());

        try {
            $transaction->update(['reason' => 'rewritten']);
            $this->fail('Expected update to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $transaction->lines()->first()->delete();
    }

    #[Test]
    public function transaction_numbers_are_sequential_within_the_month(): void
    {
        $first = $this->receive('1', $this->lot());
        $second = $this->receive('1', $this->lot());

        $this->assertMatchesRegularExpression('/^IT-\d{4}-\d{6}$/', $first->number);
        $this->assertSame(
            (int) substr($first->number, -6) + 1,
            (int) substr($second->number, -6),
        );
    }

    // ---- Availability ----------------------------------------------------

    #[Test]
    public function stock_awaiting_qc_is_on_the_books_but_not_available_to_production(): void
    {
        $this->receive('30', InventoryLot::factory()->forItem($this->item)->pendingQc()->create());

        $this->assertSame('30.000000', (string) $this->balances->onHand($this->item));
        $this->assertSame('0', (string) $this->balances->availableForProduction($this->item));
    }

    #[Test]
    public function rejected_and_expired_lots_are_not_available_to_production(): void
    {
        $this->receive('10', InventoryLot::factory()->forItem($this->item)->rejected()->create());
        $this->receive('10', InventoryLot::factory()->forItem($this->item)->expired()->create());
        $this->receive('10', $this->lot());

        $this->assertSame('30.000000', (string) $this->balances->onHand($this->item));
        $this->assertSame('10.000000', (string) $this->balances->availableForProduction($this->item));
    }

    #[Test]
    public function stock_in_a_quarantine_store_is_not_available_to_production(): void
    {
        $this->receive('50', $this->lot(), $this->quarantine);

        $this->assertSame('50.000000', (string) $this->balances->onHand($this->item));
        $this->assertSame('0', (string) $this->balances->availableForProduction($this->item));
    }

    // ---- Reservations ----------------------------------------------------

    #[Test]
    public function a_reservation_lowers_what_is_available_without_moving_stock(): void
    {
        $this->receive('100', $this->lot());

        $this->reservations->reserve($this->item, $this->item, $this->store, '60');

        $this->assertSame('100.000000', (string) $this->balances->onHand($this->item, $this->store));
        $this->assertSame('60.000000', (string) $this->balances->reserved($this->item, $this->store));
        $this->assertSame('40.000000', (string) $this->balances->available($this->item, $this->store));
    }

    #[Test]
    public function reserving_more_than_is_free_is_refused_and_rolled_back(): void
    {
        $this->receive('100', $this->lot());
        $this->reservations->reserve($this->item, $this->item, $this->store, '60');

        try {
            $this->reservations->reserve($this->item, $this->item, $this->store, '41');
            $this->fail('Expected the reservation to be refused.');
        } catch (InsufficientStockException $e) {
            $this->assertSame('1.000000', (string) $e->shortage);
        }

        // The partial hold the second attempt took before running out must
        // not survive its failure.
        $this->assertSame('60.000000', (string) $this->balances->reserved($this->item, $this->store));
    }

    #[Test]
    public function reservations_draw_on_the_lot_that_expires_first(): void
    {
        $later = InventoryLot::factory()->forItem($this->item)->expiringOn(now()->addMonths(6)->toDateString())->create();
        $sooner = InventoryLot::factory()->forItem($this->item)->expiringOn(now()->addMonth()->toDateString())->create();

        $this->receive('50', $later);
        $this->receive('50', $sooner);

        $created = $this->reservations->reserve($this->item, $this->item, $this->store, '70');

        $this->assertCount(2, $created);
        $this->assertSame($sooner->id, $created[0]->lot_id);
        $this->assertSame('50.000000', $created[0]->quantity);
        $this->assertSame($later->id, $created[1]->lot_id);
        $this->assertSame('20.000000', $created[1]->quantity);
    }

    #[Test]
    public function releasing_a_reservation_gives_the_stock_back(): void
    {
        $this->receive('100', $this->lot());
        $reservation = $this->reservations->reserve($this->item, $this->item, $this->store, '60')->first();

        $this->reservations->release($reservation);

        $this->assertSame('0.000000', (string) $this->balances->reserved($this->item, $this->store));
        $this->assertSame(ReservationStatus::Released, $reservation->fresh()->status);
    }

    #[Test]
    public function consuming_a_reservation_posts_to_the_ledger_and_lowers_both_figures(): void
    {
        $lot = $this->lot();
        $this->receive('100', $lot);
        $reservation = $this->reservations->reserve($this->item, $this->item, $this->store, '60')->first();

        $transaction = $this->reservations->consume($reservation, '45');

        $this->assertSame(InventoryTransactionType::ProductionConsumption, $transaction->type);
        $this->assertSame('-45.000000', $transaction->lines->first()->quantity);
        $this->assertSame('55.000000', (string) $this->balances->onHand($this->item, $this->store));
        $this->assertSame('15.000000', (string) $this->balances->reserved($this->item, $this->store));

        $reservation->refresh();
        $this->assertSame('45.000000', $reservation->consumed_quantity);
        $this->assertSame(ReservationStatus::Active, $reservation->status);

        $this->reservations->consume($reservation, '15');
        $this->assertSame(ReservationStatus::Consumed, $reservation->fresh()->status);
        $this->assertSame('0.000000', (string) $this->balances->reserved($this->item, $this->store));
    }

    #[Test]
    public function consuming_more_than_is_outstanding_is_refused(): void
    {
        $this->receive('100', $this->lot());
        $reservation = $this->reservations->reserve($this->item, $this->item, $this->store, '10')->first();

        $this->expectException(InvalidArgumentException::class);

        $this->reservations->consume($reservation, '10.5');
    }

    #[Test]
    public function reserved_stock_cannot_leave_by_another_door(): void
    {
        $lot = $this->lot();
        $this->receive('100', $lot);
        $this->reservations->reserve($this->item, $this->item, $this->store, '80');

        // 20 is free; asking for 30 as damage would eat into the reservation.
        $this->expectException(InsufficientStockException::class);

        $this->ledger->issue($this->item, $this->store, '30', $lot, InventoryTransactionType::Damage);
    }

    #[Test]
    public function cancelling_releases_every_reservation_held_for_the_order(): void
    {
        $this->receive('50', $this->lot());
        $this->receive('50', $this->lot());
        $this->reservations->reserve($this->item, $this->item, $this->store, '80');

        $released = $this->reservations->releaseAllFor($this->item);

        $this->assertSame(2, $released);
        $this->assertSame('0.000000', (string) $this->balances->reserved($this->item, $this->store));
    }

    // ---- Rebuild ---------------------------------------------------------

    #[Test]
    public function the_cache_can_be_rebuilt_from_the_ledger(): void
    {
        $lot = $this->lot();
        $this->receive('100', $lot);
        $this->ledger->issue($this->item, $this->store, '30', $lot);
        $this->reservations->reserve($this->item, $this->item, $this->store, '25');

        // Corrupt the cache on purpose.
        StockBalance::query()->update(['on_hand' => '999', 'reserved' => '0']);

        $rebuilt = $this->balances->rebuild();

        $this->assertSame(1, $rebuilt);
        $this->assertSame('70.000000', (string) $this->balances->onHand($this->item, $this->store));
        $this->assertSame('25.000000', (string) $this->balances->reserved($this->item, $this->store));
    }

    // ---- Numbering -------------------------------------------------------

    #[Test]
    public function batch_numbers_carry_the_type_the_date_and_a_daily_sequence(): void
    {
        $generator = app(BatchNumberGenerator::class);
        $today = now()->format('ymd');

        $this->assertSame("RM{$today}-001", $generator->generate($this->item));
        $this->assertSame("RM{$today}-002", $generator->generate($this->item));
    }

    #[Test]
    public function sequences_are_independent_per_key(): void
    {
        $sequences = app(SequenceService::class);

        $this->assertSame(1, $sequences->next('a'));
        $this->assertSame(2, $sequences->next('a'));
        $this->assertSame(1, $sequences->next('b'));
        $this->assertSame('GRN-2609-00001', $sequences->nextNumber('GRN', '2609'));
    }

    // ---- Alerts ----------------------------------------------------------

    #[Test]
    public function alert_levels_follow_the_items_thresholds(): void
    {
        $alerts = app(StockAlertService::class);

        // minimum 20, reorder 50, moderate ceiling 100 (x2)
        $this->assertSame(StockAlertLevel::OutOfStock, $alerts->levelFor($this->item, '0'));
        $this->assertSame(StockAlertLevel::Critical, $alerts->levelFor($this->item, '20'));
        $this->assertSame(StockAlertLevel::Low, $alerts->levelFor($this->item, '20.000001'));
        $this->assertSame(StockAlertLevel::Low, $alerts->levelFor($this->item, '50'));
        $this->assertSame(StockAlertLevel::Moderate, $alerts->levelFor($this->item, '50.000001'));
        $this->assertSame(StockAlertLevel::Moderate, $alerts->levelFor($this->item, '100'));
        $this->assertSame(StockAlertLevel::Healthy, $alerts->levelFor($this->item, '100.000001'));
    }

    #[Test]
    public function an_item_without_thresholds_is_never_guessed_at(): void
    {
        $alerts = app(StockAlertService::class);
        $item = RawMaterial::factory()->create(['reorder_level' => null, 'minimum_stock' => null]);

        $this->assertSame(StockAlertLevel::OutOfStock, $alerts->levelFor($item, '0'));
        $this->assertSame(StockAlertLevel::Healthy, $alerts->levelFor($item, '0.5'));
    }

    #[Test]
    public function alert_levels_sort_by_severity(): void
    {
        $this->assertGreaterThan(StockAlertLevel::Low->severity(), StockAlertLevel::Critical->severity());
        $this->assertGreaterThan(StockAlertLevel::Moderate->severity(), StockAlertLevel::Low->severity());
        $this->assertFalse(StockAlertLevel::Healthy->needsAttention());
        $this->assertTrue(StockAlertLevel::Moderate->needsAttention());
    }
}
