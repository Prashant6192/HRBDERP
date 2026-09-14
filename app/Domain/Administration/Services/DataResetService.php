<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Administration\Exceptions\DataResetException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Clearing the data a factory put in while it was learning the system.
 *
 * Testing leaves a trail — trial deliveries, half-planned batches, stock
 * that was never really there — and none of it belongs in the opening
 * figures. This clears exactly the parts asked for and nothing else.
 *
 * Three things are never touched, whatever is asked: people and their
 * roles, the reference data the ERP is built on (units, departments,
 * facility and store categories), and the audit trail, which is
 * append-only at the database and is the record that the clearing itself
 * happened.
 *
 * Deletes go through the query builder rather than the models, because
 * ledger postings refuse to be deleted by the models on purpose.
 */
class DataResetService
{
    /**
     * What a person may choose to clear, and what each depends on.
     *
     * The dependencies are not a matter of taste: they are the foreign
     * keys. A batch cannot be deleted while a delivery still points at it.
     *
     * @var array<string, array{label: string, description: string, count: string, requires: list<string>, danger?: bool}>
     */
    public const array SCOPES = [
        'production' => [
            'label' => 'Plans and batches',
            'description' => 'Production plans, material requests and manufacturing orders with their stages, adjustments and scans.',
            'count' => 'manufacturing_orders',
            'requires' => [],
        ],
        'purchasing' => [
            'label' => 'Deliveries and QC',
            'description' => 'Goods receipts with their uploaded bills, and every QC decision taken on them.',
            'count' => 'goods_receipts',
            'requires' => [],
        ],
        'stock' => [
            'label' => 'Stock and movements',
            'description' => 'Every batch, balance, ledger posting, reservation, stock count, transfer and floor photo. The stores go back to empty. Deliveries go with it, because they point at the batches they brought in.',
            'count' => 'inventory_lots',
            'requires' => ['purchasing'],
        ],
        'workflow' => [
            'label' => 'Approvals, alerts and documents',
            'description' => 'Pending and signed approvals, notifications, escalations and controlled documents.',
            'count' => 'approvals',
            'requires' => [],
        ],
        'formulas' => [
            'label' => 'Formulations',
            'description' => 'Every recipe, its versions and ingredients. Plans and batches go first, because they are made from these.',
            'count' => 'formulas',
            'requires' => ['production'],
        ],
        'materials' => [
            'label' => 'Materials and products',
            'description' => 'Raw materials, packaging, finished goods, their categories, conversions and per-store levels.',
            'count' => 'items',
            'requires' => ['stock', 'purchasing', 'production', 'formulas'],
        ],
        'partners' => [
            'label' => 'Vendors and clients',
            'description' => 'Suppliers and contract clients, with the artwork and QC specifications held for them.',
            'count' => 'vendors',
            'requires' => ['purchasing'],
        ],
        'facilities' => [
            'label' => 'Facilities and stores',
            'description' => 'The plants, their stores and racks, and who is assigned where. Rarely wanted: the factory itself does not change because a trial did.',
            'count' => 'warehouses',
            'requires' => ['stock', 'purchasing', 'production'],
            'danger' => true,
        ],
    ];

    /**
     * Every table that can be cleared, in the only order the foreign keys
     * allow — children before the rows they point at — tagged with the
     * scope it belongs to. Deleting walks this list once, skipping tables
     * whose scope was not chosen.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const array ORDER = [
        ['floor_photos', 'stock'],
        ['manufacturing_order_scans', 'production'],
        ['manufacturing_order_stage_events', 'production'],
        ['manufacturing_order_adjustments', 'production'],
        ['manufacturing_order_lines', 'production'],
        ['stock_count_lines', 'stock'],
        ['stock_counts', 'stock'],
        ['stock_transfer_lines', 'stock'],
        ['stock_transfers', 'stock'],
        ['stock_reservations', 'stock'],
        ['stock_balances', 'stock'],
        ['inventory_transaction_lines', 'stock'],
        ['inventory_transactions', 'stock'],
        ['qc_inspections', 'purchasing'],
        ['goods_receipt_lines', 'purchasing'],
        ['goods_receipts', 'purchasing'],
        ['manufacturing_orders', 'production'],
        ['material_request_lines', 'production'],
        ['material_requests', 'production'],
        ['production_plan_lines', 'production'],
        ['production_plans', 'production'],
        // Nothing points at a batch by now.
        ['inventory_lots', 'stock'],
        ['approval_actions', 'workflow'],
        ['approval_steps', 'workflow'],
        ['approvals', 'workflow'],
        ['notifications', 'workflow'],
        ['escalations', 'workflow'],
        ['documents', 'workflow'],
        ['formula_unlocks', 'formulas'],
        ['formula_ingredients', 'formulas'],
        ['formula_versions', 'formulas'],
        ['formulas', 'formulas'],
        ['store_item_levels', 'materials'],
        ['product_packaging_lines', 'materials'],
        ['item_uom_conversions', 'materials'],
        ['items', 'materials'],
        ['item_categories', 'materials'],
        ['client_artworks', 'partners'],
        ['client_qc_specs', 'partners'],
        ['clients', 'partners'],
        ['vendors', 'partners'],
        ['employee_assignments', 'facilities'],
        ['warehouse_locations', 'facilities'],
        ['warehouses', 'facilities'],
        ['facilities', 'facilities'],
    ];

    /**
     * Columns a row uses to point at another row in the same table. They
     * are let go of first, or the table cannot be emptied at all.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array SELF_REFERENCES = [
        'stock' => ['inventory_transactions', 'reverses_transaction_id'],
    ];

    /**
     * How much of each scope there is to clear.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (self::SCOPES as $key => $scope) {
            $counts[$key] = DB::table($scope['count'])->count();
        }

        return $counts;
    }

    /**
     * The scopes asked for, plus everything they depend on.
     *
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public function withDependencies(array $scopes): array
    {
        $resolved = [];

        $add = function (string $scope) use (&$add, &$resolved): void {
            if (in_array($scope, $resolved, true) || ! isset(self::SCOPES[$scope])) {
                return;
            }

            $resolved[] = $scope;

            foreach (self::SCOPES[$scope]['requires'] as $required) {
                $add($required);
            }
        };

        foreach ($scopes as $scope) {
            $add($scope);
        }

        // Named in the order they are offered, so the list reads the same
        // way the screen does.
        return array_values(array_filter(array_keys(self::SCOPES), fn (string $key) => in_array($key, $resolved, true)));
    }

    /**
     * What choosing these scopes drags in with it.
     *
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public function addedByDependency(array $scopes): array
    {
        return array_values(array_diff($this->withDependencies($scopes), $scopes));
    }

    /**
     * Clear the scopes given, all or nothing.
     *
     * @param  list<string>  $scopes
     * @return array<string, int> rows cleared per table
     *
     * @throws DataResetException
     */
    public function reset(array $scopes, bool $restartNumbering = false): array
    {
        $unknown = array_diff($scopes, array_keys(self::SCOPES));

        if ($unknown !== []) {
            throw new DataResetException('There is nothing called '.implode(', ', $unknown).' to clear.');
        }

        if ($scopes === []) {
            throw new DataResetException('Choose at least one kind of data to clear.');
        }

        $resolved = $this->withDependencies($scopes);
        $cleared = [];

        DB::transaction(function () use ($resolved, $restartNumbering, &$cleared): void {
            foreach (self::SELF_REFERENCES as $scope => [$table, $column]) {
                if (in_array($scope, $resolved, true)) {
                    DB::table($table)->whereNotNull($column)->update([$column => null]);
                }
            }

            foreach (self::ORDER as [$table, $scope]) {
                if (! in_array($scope, $resolved, true)) {
                    continue;
                }

                $rows = DB::table($table)->delete();

                if ($rows > 0) {
                    $cleared[$table] = $rows;
                }
            }

            if ($restartNumbering) {
                $rows = DB::table('document_sequences')->delete();

                if ($rows > 0) {
                    $cleared['document_sequences'] = $rows;
                }
            }
        });

        // Files belonging to records that no longer exist.
        if (in_array('purchasing', $resolved, true)) {
            $this->forgetDirectory('goods-receipts');
        }

        if (in_array('stock', $resolved, true)) {
            $this->forgetDirectory('floor-photos');
        }

        return $cleared;
    }

    /**
     * The tables one scope owns, for anyone who wants to see the shape of it.
     *
     * @return list<string>
     */
    public function tablesFor(string $scope): array
    {
        return array_values(array_map(
            fn (array $row) => $row[0],
            array_filter(self::ORDER, fn (array $row) => $row[1] === $scope),
        ));
    }

    private function forgetDirectory(string $directory): void
    {
        $disk = Storage::disk('local');

        if ($disk->exists($directory)) {
            $disk->deleteDirectory($directory);
        }
    }
}
