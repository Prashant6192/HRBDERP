<?php

declare(strict_types=1);

use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facilities above stores.
 *
 * Every stock table already points at `warehouses`, so a warehouse row IS a
 * store and keeps its id and code. What this adds is the physical facility a
 * store belongs to, configurable facility types and store categories, the
 * rack/bin hierarchy under a store, who works where, store-specific stock
 * thresholds, and transfers between facilities with an in-transit position.
 *
 * Existing rows are migrated in place: the stores that exist are attached
 * to one facility (Rudrapur when the data says so), categorised by what
 * they already held. Nothing is deleted and no reference changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('default_capabilities')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
        });

        Schema::create('store_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('badge', 12);
            // A WarehouseType value: the behaviour this category carries.
            $table->string('kind', 32)->index();
            $table->string('icon', 48)->nullable();
            $table->string('color', 24)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
        });

        Schema::create('facilities', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->foreignId('facility_type_id')->constrained('facility_types')->restrictOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 128)->nullable();
            $table->string('state', 128)->nullable();
            $table->string('pincode', 16)->nullable();
            $table->string('country', 128)->default('India');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('gstin', 20)->nullable();

            // What this facility is allowed to do. Workflows check these,
            // never a facility's name.
            $table->boolean('can_store')->default(true);
            $table->boolean('can_receive')->default(true);
            $table->boolean('can_qc')->default(false);
            $table->boolean('can_manufacture')->default(false);
            $table->boolean('can_pack')->default(false);
            $table->boolean('can_dispatch')->default(true);
            $table->boolean('can_return')->default(false);

            // Opening stock is for going live; switched off once operations run.
            $table->boolean('opening_stock_enabled')->default(true);

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['facility_type_id', 'is_active']);
            $table->index('city');
        });

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->foreignId('facility_id')->nullable()->after('name')->constrained('facilities')->nullOnDelete();
            $table->foreignId('store_category_id')->nullable()->after('facility_id')->constrained('store_categories')->restrictOnDelete();
            // The in-transit position and the like: created by the system, not listed as a user's store.
            $table->boolean('is_system')->default(false)->after('is_active');
            $table->unsignedSmallInteger('sort_order')->default(100)->after('is_system');

            $table->index(['facility_id', 'is_active']);
        });

        Schema::table('warehouse_locations', function (Blueprint $table): void {
            // Zone → rack → shelf → bin.
            $table->foreignId('parent_id')->nullable()->after('warehouse_id')->constrained('warehouse_locations')->cascadeOnDelete();
        });

        Schema::create('employee_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('warehouses')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->string('designation', 128)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['facility_id', 'status']);
            $table->index(['store_id', 'status']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX employee_assignments_one_active ON employee_assignments (user_id, facility_id, store_id) NULLS NOT DISTINCT WHERE status = 'active'"
        );

        Schema::create('store_item_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->decimal('minimum_stock', 20, 6)->nullable();
            $table->decimal('reorder_level', 20, 6)->nullable();
            $table->decimal('moderate_multiplier', 8, 3)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['warehouse_id', 'item_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('source_facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 24)->default('draft')->index();
            $table->boolean('requires_inspection')->default(false);
            $table->date('expected_at')->nullable();
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('vehicle_ref', 64)->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source_facility_id', 'status']);
            $table->index(['destination_facility_id', 'status']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('quantity_requested', 20, 6);
            $table->decimal('quantity_dispatched', 20, 6)->default('0');
            $table->decimal('quantity_received', 20, 6)->default('0');
            $table->decimal('quantity_written_off', 20, 6)->default('0');
            $table->string('discrepancy_notes')->nullable();
            $table->timestamps();

            $table->index(['stock_transfer_id', 'line_no']);
        });

        DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT stock_transfer_lines_qty_positive CHECK (quantity_requested > 0)');

        // Production is done somewhere.
        Schema::table('production_plans', function (Blueprint $table): void {
            $table->foreignId('facility_id')->nullable()->after('number')->constrained('facilities')->restrictOnDelete();
        });

        Schema::table('manufacturing_orders', function (Blueprint $table): void {
            $table->foreignId('facility_id')->nullable()->after('number')->constrained('facilities')->restrictOnDelete();
        });

        // Balances and holds may name the rack or bin, when the store uses them.
        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('lot_id')->constrained('warehouse_locations')->restrictOnDelete();
        });

        DB::statement('DROP INDEX IF EXISTS stock_balances_unique');
        DB::statement('CREATE UNIQUE INDEX stock_balances_unique ON stock_balances (item_id, warehouse_id, lot_id, location_id) NULLS NOT DISTINCT');

        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('lot_id')->constrained('warehouse_locations')->restrictOnDelete();
        });

        $this->seedReferenceData();
        $this->migrateExistingWarehouses();
    }

    public function down(): void
    {
        Schema::table('stock_reservations', fn (Blueprint $t) => $t->dropConstrainedForeignId('location_id'));
        DB::statement('DROP INDEX IF EXISTS stock_balances_unique');
        Schema::table('stock_balances', fn (Blueprint $t) => $t->dropConstrainedForeignId('location_id'));
        DB::statement('CREATE UNIQUE INDEX stock_balances_unique ON stock_balances (item_id, warehouse_id, lot_id) NULLS NOT DISTINCT');
        Schema::table('manufacturing_orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('facility_id'));
        Schema::table('production_plans', fn (Blueprint $t) => $t->dropConstrainedForeignId('facility_id'));
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('store_item_levels');
        Schema::dropIfExists('employee_assignments');
        Schema::table('warehouse_locations', fn (Blueprint $t) => $t->dropConstrainedForeignId('parent_id'));
        DB::table('warehouses')->where('is_system', true)->delete();
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('facility_id');
            $table->dropConstrainedForeignId('store_category_id');
            $table->dropColumn(['is_system', 'sort_order']);
        });
        Schema::dropIfExists('facilities');
        Schema::dropIfExists('store_categories');
        Schema::dropIfExists('facility_types');
    }

    private function seedReferenceData(): void
    {
        (new ReferenceDataSeeder)->run();
    }

    private function migrateExistingWarehouses(): void
    {
        $now = now();
        $categories = DB::table('store_categories')->pluck('id', 'kind');

        // The system's in-transit position: stock on a lorry between
        // facilities belongs to no store and no facility, but must still be
        // on the books.
        DB::table('warehouses')->insertOrIgnore([
            'code' => 'SYS-TRANSIT', 'name' => 'In Transit', 'type' => 'in_transit', 'store_category_id' => $categories['in_transit'],
            'is_quarantine' => true, 'is_active' => true, 'is_system' => true, 'sort_order' => 999,
            'country' => 'India', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $existing = DB::table('warehouses')->whereNull('facility_id')->where('is_system', false)->orderBy('id')->get();

        if ($existing->isEmpty()) {
            return;
        }

        // One facility for everything that exists today. If any store names
        // Rudrapur it is the Rudrapur plant; otherwise it takes the first
        // store's city. It is a manufacturing facility because production
        // already runs against these stores.
        $mentionsRudrapur = $existing->contains(fn ($w) => stripos($w->name.' '.$w->city, 'rudrapur') !== false);
        $first = $existing->first();
        $city = $mentionsRudrapur ? 'Rudrapur' : ($first->city ?: null);
        $stem = $city ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $city), 0, 3)) : 'MAIN';

        $facilityId = DB::table('facilities')->insertGetId([
            'code' => "FAC-{$stem}-001",
            'name' => $mentionsRudrapur ? 'Rudrapur Manufacturing Facility' : (($city ?: $first->name).' Facility'),
            'facility_type_id' => DB::table('facility_types')->where('code', 'MFG')->value('id'),
            'manager_id' => $first->manager_id,
            'address_line_1' => $first->address_line_1, 'address_line_2' => $first->address_line_2,
            'city' => $city ?: $first->city, 'state' => $first->state, 'pincode' => $first->pincode, 'country' => $first->country ?: 'India',
            'gstin' => $first->gstin,
            'can_store' => true, 'can_receive' => true, 'can_qc' => true, 'can_manufacture' => true,
            'can_pack' => true, 'can_dispatch' => true, 'can_return' => true,
            'opening_stock_enabled' => true, 'is_active' => true,
            'notes' => 'Created by the facilities migration from the existing warehouse records.',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ($existing as $warehouse) {
            DB::table('warehouses')->where('id', $warehouse->id)->update([
                'facility_id' => $facilityId,
                'store_category_id' => $categories[$warehouse->type] ?? $categories['general'],
                'updated_at' => $now,
            ]);
        }

        DB::table('production_plans')->whereNull('facility_id')->update(['facility_id' => $facilityId]);
        DB::table('manufacturing_orders')->whereNull('facility_id')->update(['facility_id' => $facilityId]);
    }
};
