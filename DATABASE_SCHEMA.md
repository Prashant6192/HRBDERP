# Database schema

PostgreSQL 16. Every table is created by a migration in `database/migrations`;
nothing is changed by hand on a server.

---

## Principles

### Money and quantities are never floating point

Every quantity, price, cost and percentage is a PostgreSQL `NUMERIC` column,
read into PHP as a string, and calculated with `brick/math`. Binary floating
point cannot represent `0.1` exactly, and an ERP that reconciles stock against
a physical count or a supplier invoice cannot afford that.

| Kind of value     | Column type       | Why                                                                           |
| ----------------- | ----------------- | ----------------------------------------------------------------------------- |
| Quantity          | `NUMERIC(20, 6)`  | Formulations are percentages; scaled to a batch they produce long decimals    |
| Money             | `NUMERIC(20, 4)`  | Four places survives per-unit costs on large batches                          |
| Percentage        | `NUMERIC(12, 6)`  | GST rates and formula percentages                                             |
| Conversion factor | `NUMERIC(30, 12)` | Must hold both 1,000,000 (tonne→gram) and 0.001 (milligram→gram) without loss |

The scales are declared in `config/erp.php` so calculations and columns agree.

### Constraints belong in the database

Application validation catches mistakes and gives a good error message. Database
constraints make a class of bad row _impossible_, including from a console, a
migration, or a bug. Both are used.

### Records are deactivated, not deleted

Master data carries `is_active` and `deleted_at`. Ledgers, batches and approvals
refer to these rows for years. `forceDelete` is denied by policy for everyone.

---

## Reference data

### `uoms` — units of measure

Every unit belongs to one **dimension** — mass, volume or count — and each
dimension has one canonical **base unit**: gram, millilitre and piece.
`factor_to_base` says how many base units make one of this unit, so `KG` carries
1000 and `MG` carries 0.001.

Conversion between two units of the same dimension is therefore one multiply and
one divide, performed by `UnitConversionService`. Nothing else in the codebase
multiplies by 1000.

| Column                 | Type                 | Notes                                                         |
| ---------------------- | -------------------- | ------------------------------------------------------------- |
| `code`                 | `varchar(16)` unique | `KG`, `ML`, `PCS`                                             |
| `dimension`            | `varchar(16)`        | `mass`, `volume`, `count`                                     |
| `is_base`              | `boolean`            | Exactly one per dimension, enforced by a partial unique index |
| `requires_item_factor` | `boolean`            | See below                                                     |
| `factor_to_base`       | `NUMERIC(30,12)`     | `CHECK (factor_to_base > 0)`                                  |
| `display_scale`        | `smallint`           | Decimal places to show; pieces are whole numbers              |

Two constraints are worth naming:

```sql
CREATE UNIQUE INDEX uoms_one_base_per_dimension ON uoms (dimension) WHERE is_base;
ALTER TABLE uoms ADD CONSTRAINT uoms_factor_positive CHECK (factor_to_base > 0);
```

The first makes "exactly one base unit per dimension" a fact rather than a
convention. The second stops a zero or negative factor, which would silently
corrupt every quantity that passed through it.

**Pack units.** A carton is not a quantity. A carton of 200 ml bottles holds 24;
a carton of caps holds 5,000. Such units are flagged `requires_item_factor`, and
`UnitConversionService` refuses to convert them without a factor supplied by the
item. Without the flag they would need a global factor anyway — almost certainly
1 — and a carton would quietly convert to a single piece.

**Crossing dimensions.** Litres do not convert to kilograms. That requires a
density, which is a property of a material rather than of a unit, and lives on
`items.density_g_per_ml`. Because the base units are gram and millilitre, a
g/ml density bridges them exactly.

### `item_uom_conversions`

A factor belonging to one item: "one carton of _this_ bottle holds 120 pieces."
Unique on `(item_id, from_uom_id, to_uom_id)`, `CHECK (factor > 0)`. A single row
defines both directions — the reverse is the reciprocal — so nobody has to
remember to enter it twice.

### `departments`, `item_categories`

Straightforward reference tables. Categories are self-referencing for
sub-categories and may be scoped to one item type.

---

## Master data

### `items` — one table for everything stockable

Raw materials, packaging materials and finished goods share this table,
discriminated by `type`.

They are presented as three separate modules because different people maintain
them and they carry different fields. They share a table because they share a
life: the same inventory ledger moves all of them, the same purchase order can
buy any of them, and a finished good is consumed as an input by the next
production order up the chain. Three tables would mean three of every ledger,
reservation and costing relation.

`RawMaterial`, `PackagingMaterial` and `Product` each apply a global scope for
their type, so a module queries only its own rows without repeating a `where`
clause. Route bindings resolve the concrete subclass, so `/products/{id}` cannot
be used to read a raw material.

Notable columns:

| Column                                            | Type                 | Notes                                                                                           |
| ------------------------------------------------- | -------------------- | ----------------------------------------------------------------------------------------------- |
| `code`                                            | `varchar(64)` unique | Unique across **all** item types — one code space, so a code identifies one thing on a document |
| `type`                                            | `varchar(32)`        | `raw_material`, `packaging_material`, `finished_good`, `semi_finished`, `consumable`            |
| `stock_uom_id`                                    | FK `uoms`            | Stock is held and the ledger written in this unit                                               |
| `purchase_uom_id`                                 | FK `uoms` nullable   | When the buying unit differs                                                                    |
| `density_g_per_ml`                                | `NUMERIC(16,6)`      | The only sanctioned bridge between mass and volume                                              |
| `standard_cost`                                   | `NUMERIC(20,4)`      | Current cost. Historical batch costs are never rewritten from here                              |
| `mrp`, `net_content`, `brand`, `barcode`          |                      | Finished goods                                                                                  |
| `is_batch_tracked`                                | `boolean`            | What makes a batch traceable to its inputs                                                      |
| `requires_qc`                                     | `boolean`            | Received stock is quarantined until QC releases it                                              |
| `reorder_level`, `minimum_stock`, `maximum_stock` | `NUMERIC(20,6)`      |                                                                                                 |

```sql
ALTER TABLE items ADD CONSTRAINT items_non_negative_levels CHECK (
    (reorder_level    IS NULL OR reorder_level    >= 0) AND
    (minimum_stock    IS NULL OR minimum_stock    >= 0) AND
    (maximum_stock    IS NULL OR maximum_stock    >= 0) AND
    (standard_cost    IS NULL OR standard_cost    >= 0) AND
    (mrp              IS NULL OR mrp              >= 0) AND
    (density_g_per_ml IS NULL OR density_g_per_ml >  0)
);
```

A negative stock level is always a data-entry error rather than a meaningful
instruction, and a density of zero would make the mass/volume bridge divide by
zero.

### `facility_types`, `store_categories`

Editable reference data, seeded by `ReferenceDataSeeder` and safe to re-seed
(matched by code, only created when missing). A facility type carries the
`default_capabilities` a new facility of that type starts with. A store
category carries a `badge` (RM, PM, FG, QUAR…) and a `kind` — a
`WarehouseType` value, the behaviour workflows rely on — which is fixed once
stores use the category. Both have `is_system` and `is_active`; neither is
ever deleted from a live system.

### `facilities`

A site: code, name, type, manager, address and contact, GSTIN, seven boolean
capabilities (`can_store`, `can_receive`, `can_qc`, `can_manufacture`,
`can_pack`, `can_dispatch`, `can_return`), `opening_stock_enabled`,
`is_active`, soft deletes. `production_plans.facility_id` and
`manufacturing_orders.facility_id` name where a batch is made; the service
layer refuses either for a facility without `can_manufacture`.

### `warehouses`, `warehouse_locations` — stores

The table keeps its original name because every stock table points at it: a
`warehouses` row **is** a store. It now carries `facility_id`,
`store_category_id`, `is_system` and `sort_order`. `type` and `is_quarantine`
follow from the category and are kept in step on save, so everything written
against the type keeps working. One system store, `SYS-TRANSIT`, belongs to
no facility and holds stock that has left one site and not yet reached the
next. A location code is unique **within** its store; locations nest through
`parent_id` (zone → rack → shelf → bin) and are optional. Stock in a store
flagged `is_quarantine` is on the books but cannot be issued until QC
releases it.

The facilities migration converts existing rows in place: the stores that
exist are attached to one manufacturing facility (Rudrapur when any store
names it) and categorised by what they already held. No id, code or ledger
line changes.

### `employee_assignments`

Where a person works: `user_id`, `facility_id`, optional `store_id`,
`is_primary`, `designation`, `effective_from` / `effective_to`, `status`,
`assigned_by`. A partial unique index allows one active row per
(user, facility, store) with `NULLS NOT DISTINCT`, so a person cannot be
assigned to the same place twice while an ended assignment stays on record.

### `store_item_levels`

Optional per-store overrides of an item's `minimum_stock`, `reorder_level`
and moderate multiplier; unique per (store, item). Absent figures fall back
to the item.

### `vendors`

```sql
CREATE UNIQUE INDEX vendors_gstin_unique ON vendors (gstin)
    WHERE gstin IS NOT NULL AND deleted_at IS NULL;
```

A GSTIN identifies one legal entity, so two live vendor records sharing one is a
duplicate rather than a second supplier. Scoping the index to live rows lets a
soft-deleted vendor be re-registered later.

---

## People and access

### `users`

The framework's table, extended into an employee record: `employee_code`,
`department_id`, `designation`, `phone`, `status`, `deactivated_at`,
`last_login_at`, `last_login_ip`, `must_change_password`, and soft deletes.

`formula_pin_hash` holds the second factor guarding formulations. It is hashed
like a password, hidden from serialisation, and never returned by any endpoint.

### `roles`, `permissions`, and their pivots

Created by `spatie/laravel-permission`. The contents are generated from
`PermissionCatalogue` and `RoleName`; see
[USER_ROLES_PERMISSIONS.md](USER_ROLES_PERMISSIONS.md).

---

## Cross-cutting

### `audit_logs`

Append-only. `old_values`, `new_values` and `context` are `jsonb`. Indexed on
`(auditable_type, auditable_id)`, `(user_id, created_at)`, `action` and
`created_at`.

The polymorphic pair is deliberately **not** a constrained relation: audit rows
must outlive the row they describe. `user_id` is nullable and `ON DELETE SET
NULL`, because a user record must never have to be erased to hide an action, and
the copied `user_name` and `user_email` preserve who it was.

See [SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md) for the immutability
guarantees and the production `REVOKE`.

### `approvals`, `approval_steps`, `approval_actions`

One approval engine for the whole ERP. Formula releases, purchase orders,
production orders, stock adjustments, write-offs and price overrides all need
the same thing — a request, an ordered set of steps, a record of who did what,
and a final state — so they get it from these three tables rather than from six
near-identical implementations.

A workflow is identified by `workflow_key`, so adding one is configuration
rather than schema. A step names a required permission, a required role, or
both; holding either is enough unless `require_both` is set. A step naming
neither is actionable by nobody, which is the safe reading of an incomplete
definition.

---

## Inventory

The ledger is the truth; everything else about stock is derived from it.

### `document_sequences`

One row per numbering key (`grn:2609`, `pmr:2609`, `batch:RM:250909`,
`formula`), locked `FOR UPDATE` when a number is taken, so numbers never repeat
and never skip.

### `inventory_lots`

A batch of an item: `batch_number` (unique), supplier reference, vendor,
manufactured / received / expiry dates, `qc_status` (not_required, pending,
approved, rejected, on_hold), `initial_quantity`, `unit_cost`, and a
polymorphic `source` — the goods receipt line or manufacturing order it came
from.

### `inventory_transactions`, `inventory_transaction_lines`

Append-only. A transaction has a type (`GRN_RECEIPT`, `QC_RELEASE`,
`QC_REJECTION`, `PRODUCTION_CONSUMPTION`, `PRODUCTION_OUTPUT`,
`STOCK_TRANSFER`, adjustments, damage, expiry, sample, dispatch), a warehouse,
a polymorphic `reference`, a reason and `transacted_at`; its lines carry
`item_id`, `warehouse_id`, `lot_id`, a signed `quantity` in the item's stock
unit, and `unit_cost`. Nothing here is ever updated.

### `stock_transfers`, `stock_transfer_lines`

An inter-facility transfer: source and destination facility and store,
`status` (draft, requested, approved, packed, dispatched, in_transit,
partially_received, received, discrepancy, rejected, cancelled),
`requires_inspection`, and who requested, approved, dispatched and received
it. Lines carry the item, the batch (one line per batch once approved), the
unit, and `quantity_requested` / `quantity_dispatched` / `quantity_received`
/ `quantity_written_off`, with a `CHECK (quantity_requested > 0)`. The stock
itself moves only through ledger postings that reference the transfer:
source → `SYS-TRANSIT` on dispatch, `SYS-TRANSIT` → destination (or the
destination's quarantine) on receipt, and a `DAMAGE` posting out of transit
for anything written off.

### `stock_balances`

The cache: `on_hand` and `reserved` per `(item, warehouse, lot)`, unique with
`NULLS NOT DISTINCT` so an unbatched position is one row. `CHECK (on_hand >= 0
AND reserved >= 0 AND reserved <= on_hand)`. `StockBalanceService::rebuild()`
recomputes every row from the ledger.

### `stock_reservations`

Stock held for something — a manufacturing order — per lot: `quantity`,
`consumed_quantity`, status (active, consumed, released), a polymorphic
`reservable`. Consuming lowers the reservation first, then posts the ledger
line, in one transaction.

---

## Receiving and quality

### `goods_receipts`, `goods_receipt_lines`

A delivery: number `GRN-yymm-00001`, vendor, destination warehouse, optional
`material_request_id`, `received_at`, status (draft, received, cancelled),
`posted_at`. Lines carry the quantity as entered with its unit and the same in
the stock unit, price, supplier batch, dates, and — once posted — the batch
number, lot and inspection that were created. `CHECK` keeps quantities positive.

### `qc_inspections`

One per batch that needs a decision: number `QC-yymm-00001`, lot, item, the
goods receipt line (null for a finished batch from production), quantity,
status, the store the batch is destined for, who decided and when, remarks,
and `parameters` (`jsonb`) for test results.

---

## Formulations

### `formulas`

The identity: `code` (`FRM-0001`), `name`, optional `product_id`, status
(draft, active, archived), `active_version_id`. Nothing secret lives here —
the list screen reads only this table. Soft-deleted.

### `formula_versions`

One recipe: `version_number` (unique per formula), status (draft, active,
superseded, rejected), the reference batch (`batch_size`, `batch_uom_id`),
cached `total_percentage`, notes, `change_summary`, `source` (manual, import)
and `source_reference`, who created, approved, activated and superseded it,
and when. **A partial unique index on `(formula_id) WHERE status = 'active'`
guarantees one active recipe per formula.**

### `formula_ingredients`

`line_no`, `item_id` (a raw material), `inci_name` as written at the time,
`percentage` (nullable), `is_qs`, `qs_note`, `grade`, `phase`, `purpose`.
`percentage` null with `is_qs` is the filler to 100 %; null without it is "as
required". `CHECK (percentage BETWEEN 0 AND 100)`. **Not audited** through the
general audit log — see SECURITY_ARCHITECTURE.md.

### `formula_unlocks`, `formula_access_logs`

An unlock is a short-lived grant: `user_id`, a hash of the session's token,
`unlocked_at`, `expires_at`, `revoked_at`, IP and agent. The access log is the
append-only security trail: user, formula, version, `action` (unlocked,
unlock_failed, locked_out, locked, pin_set, viewed, scaled, edited, activated,
imported), IP, agent, `context`, `occurred_at`. The model refuses updates and
deletes; production should also `REVOKE` them.

`users` carries `formula_pin_hash`, `formula_pin_set_at`,
`formula_pin_failed_attempts` and `formula_pin_locked_until`; `items` carries
`inci_name`.

---

## Planning and purchase

### `product_packaging_lines`

What each unit of a product is packed in: `packaging_material_id`,
`quantity_per_unit` (`CHECK > 0`; 0.02 for a carton of fifty), unique per
product and material.

### `production_plans`, `production_plan_lines`

A plan: `PLN-yymm-00001`, formula and the version at planning time, product,
`planned_quantity` in `planned_uom_id`, `planned_units`, status (draft,
checked, requested, in_production, completed, cancelled), `planned_start_date`,
`warnings` (`jsonb`), `checked_at`, `requested_at`. Lines are the requirement
as at `checked_at`: `store_kind` (raw_material, packaging), item, stock unit,
percentage, `required_quantity`, `available_quantity`, `shortage_quantity`,
`restock_quantity`, `level_now`, `level_after`, `notes`.

### `material_requests`, `material_request_lines`

A PMR: `PMR-yymm-00001`, plan, `store_kind`, warehouse, status (open,
partially_received, fulfilled, cancelled), `needed_by`, who raised it. Lines:
item, unit, `required_quantity`, `available_quantity`, `quantity_to_order`,
`restock_quantity`, `received_quantity` (advanced by goods receipts against
the request), `alert_level`.

---

## Manufacturing

### `manufacturing_orders`, `manufacturing_order_lines`

An order: `MO-yymm-00001`, plan, formula and version, product, planned quantity
and units, status (draft, approved, in_progress, completed, cancelled), and the
result — `output_quantity`, `output_units`, `yield_percentage`,
`output_lot_id`, `manufactured_at` — with who approved, started and completed
it. Lines: the material list with `planned_quantity`, `reserved_quantity` and
`consumed_quantity` in the stock unit. Reservations and ledger lines reference
the order polymorphically.

---

## Still to come

Zone/rack/bin balances (`stock_balances.location_id` and
`stock_reservations.location_id` exist and are part of the unique key, but
postings are still made at store level), purchase orders and vendor price history, costing (batch, per kilogram, per
unit — built on the consumption each order already records), sales orders and
dispatch, and the approval workflows that will sit on the existing
`approvals` tables. See [DEVELOPMENT_ROADMAP.md](DEVELOPMENT_ROADMAP.md).

The rule that survives all of it: **`stock_quantity` is never an editable
column.** Stock is derived from immutable `inventory_transactions`.
