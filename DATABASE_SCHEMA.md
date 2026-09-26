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
| `reorder_level`, `minimum_stock`, `maximum_stock` | `NUMERIC(20,6)`      | Alert thresholds; the minimum is the safety stock the reorder advice keeps                      |
| `lead_time_days`                                  | `smallint`           | How long a delivery takes; the reorder advice falls back to the vendor's, then a default        |
| `min_order_quantity`, `order_multiple`            | `NUMERIC(20,6)`      | The least a supplier sells and the pack it comes in; a recommendation is rounded up to them     |

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
category carries a `badge` (RM, PM, FG, QUAR, ENG…) and a `kind` — a
`WarehouseType` value, the behaviour workflows rely on (`engineering` holds
spares and maintenance consumables) — which is fixed once
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

`lead_time_days` is the supplier's usual delivery time, used by the reorder
advice when the material carries none and no delivery has been measured.

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

`is_external` marks an **outside account** (the e-commerce agency): not an
employee, so no employee code or department, left off the Employees list and
the employee pickers. `username` (nullable, unique) lets such an account sign
in without a company email; sign-in accepts the email address or the
username, either case. `OutsideAccountsSeeder` creates the agency's login
once (`divrit_processing`) and never touches it again.

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

An approval is one request about one record (`approvable` morph) under
one workflow (`workflow_key`, declared in `config/approvals.php`), with
`context` carrying the risk triggers that raised it. Its steps name who
may decide (`required_permission` / `required_role`); the requester's
approving authority may always decide, the requester never. Each decision
is an `approval_actions` row written once: `user_id`, `action`, `comment`,
`ip_address`, `user_agent`, `acted_at`, and `signature_hash` — an HMAC
over the row and the record it concerns, keyed by `ERP_SIGNATURE_KEY`, so
the row can be verified later and any change to it is detectable.

### `users.approving_authority_id`

Maker-checker: the person who authorises what this employee raises.

### `inventory_transactions.reverses_transaction_id`

A reversal posts the opposite of every line of the transaction it points
at, under type `REVERSAL`. A transaction can be reversed once; a reversal
cannot be reversed. Neither is ever edited.

A line of opening stock booked by mistake is corrected with its own
`OPENING_CORRECTION` transaction against the same lot (quantities may be
negative or positive and need not net to zero), referencing the lot and
carrying the reason. It is only posted while the lot has no other movement
and no active reservation; the lot's batch, dates, rate and
`initial_quantity` are updated with it.

### `documents`

One row per version of a controlled document: `code` shared by all
versions, `version`, `kind` (`sop`, `specification`, `artwork`, `coa`,
`formula`, `qc_standard`, `other`), `title`, `status` (`draft`,
`approved`, `superseded`, `withdrawn`), `item_id`, `client_id`, the file,
`change_summary`, `effective_from`, `supersedes_id`, `created_by`,
`approved_by`/`approved_at`, `withdrawn_by`/`withdrawn_at`. A partial
unique index keeps one approved version per code.

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

Dispatch also stamps `challan_code` (eight characters, unique) — the inward
code printed under the QR on the transfer challan. The destination's scan
fills `scanned_at` / `scanned_by`, and, when the transporter's paperwork was
uploaded, `transporter`, `transport_reference` (LR number), the document
(`transport_document_path` / `_name` / `_mime`, on the private `local` disk
under `stock-transfers/inward/`) and what was read from it
(`transport_extraction`, `jsonb`, including `matched_by`: `code` or
`document`). `StockTransferService::receive` refuses a transfer whose
`scanned_at` is null, so stock cannot reach the destination store before the
consignment is verified.

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

`entry_mode` says whether the receipt was keyed by hand (`manual`) or booked
off a scanned bill (`scan`). A scanned receipt keeps the bill itself
(`invoice_path`, `invoice_name`, `invoice_mime`; the file lives on the private
`local` disk under `goods-receipts/intake/`), everything read from it
(`extraction`, `jsonb`: vendor, invoice number and date, totals, lines and the
reader's warnings), `extraction_model` and `extracted_at`. What was read is
kept as read, even where the person corrected it on the lines.

### `qc_inspections`

One per batch that needs a decision: number `QC-yymm-00001`, lot, item, the
goods receipt line (null for a finished batch from production), quantity,
status, the store the batch is destined for, who decided and when, remarks,
and `parameters` (`jsonb`) for test results.

---

## Third-party / contract manufacturing

### `clients`

A brand the company manufactures for: `code` (`TP-001`), `name`,
`legal_name`, `gstin`, `pan`, contact, billing and shipping address columns,
`payment_terms_days`, `credit_limit`, `agreement_ref`, `agreement_expires_at`,
`notes`, `is_active`. Soft-deleted; a client with jobs, stock, products or
formulas cannot be removed.

### `client_artworks`, `client_qc_specs`

An artwork version per client (and optionally product), or for the
company's own product when `client_id` is null: `kind` (label, tube, bottle,
carton, pouch, other), `title`, `version`, `status` (pending, approved,
superseded, rejected), the approval date and approver, the artwork file —
picture or PDF — on the private `local` disk under `clients/artworks/`
(`clients/artworks/own/` for the company's). Approving a version supersedes
the other approved one of the same kind for the same owner and product. A QC specification
per client and product: `parameters` (`jsonb`: name, min, max, target, unit)
and notes; unique per pair.

### The client as a tag

Nothing else is duplicated; the client rides on the ordinary tables:

- `items.client_id` — the product is made for this client (null: own brand).
- `formulas.ownership` (company, client, joint) and `formulas.client_id`.
- `inventory_lots.owner_client_id` — the batch is the client's while it is in
  our store: material they supplied, or finished goods made for them. Every
  availability, reservation and consumption query honours it.
- `goods_receipts.owner_client_id` — the receipt was booked in the client's
  name; its batches take the owner.
- `production_plans` and `manufacturing_orders`: `manufacturing_type` (own,
  third_party), `client_id`, `client_po_ref`, `required_delivery_at`,
  `material_source` (company, client, mixed), `client_supplied_item_ids`
  (`jsonb` list); plans also `client_product_name`; orders also `charges`
  (`jsonb`: the commercial terms).
- `production_plan_lines.source` and `material_request_lines.source`
  (company, client) — whose material meets the line.

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
result — `output_quantity` (bulk made), `output_units` (good units, the ones
posted to stock), `output_lot_id`, `manufactured_at` — with who approved,
started and completed it. The batch account sits beside it: `filled_units`,
`rejected_units`, `sample_units` (good = filled − rejected − samples, checked
in the service), `bulk_leftover_quantity` in the planned unit, `loss_notes`,
and three yields to three decimals — `yield_percentage` (bulk against
planned), `packing_yield_percentage` (good against filled),
`overall_yield_percentage` (good against `planned_units`). A check constraint
keeps the counts non-negative. Lines: the material list with `planned_quantity`, `reserved_quantity` and
`consumed_quantity` in the stock unit. Reservations and ledger lines reference
the order polymorphically.

---

## Production stages and adjustments

### `manufacturing_orders` — stage columns

`current_stage` (`weighing` … `packaging`, `completed`), `stage_progress`
(0–100) and `stage_updated_at` hold where the batch is now; the history is
in the events.

### `manufacturing_order_stage_events`

Append-only readings from the floor: `stage`, `progress`, `note`,
`recorded_by`, `recorded_at`. Starting a batch writes Weighing 0;
completing it writes Completed 100.

### `manufacturing_order_adjustments`

Material a batch gave back or lost after it was issued: `kind` (`return`
or `wastage`), `item_id`, `lot_id`, `quantity` (> 0), `reason`,
`inventory_transaction_id` (the `PRODUCTION_RETURN` posting, for a
return), `recorded_by`, `recorded_at`. The total per material cannot
exceed what the batch consumed.

### `facilities.daily_capacity_kg`

What the plant can make in a day, in kilograms of bulk product. Capacity
planning and the what-if simulation divide bookings by it.

## Intelligence

### `notifications`

Laravel's database notifications: a UUID, the notifying class, the person
(`notifiable` morph), the payload (`title`, `body`, `href`, `severity`,
`category`, `key`) and `read_at`.

### `escalations`

What the escalation ladder has already raised, so a standing exception is
escalated once per level rather than once per hour: `exception_key`
(`<rule>:<subject>`, stable while the condition lasts), `rule`, `level`,
`title`, `href`, `roles` told, `recipients` count, `escalated_at`,
`resolved_at` (set when the condition clears). Unique on
`(exception_key, level)`.

## Shop floor

### `manufacturing_order_scans`

Every scan at the kettle, passed or blocked: `manufacturing_order_id`, the
raw `code`, the `item_id` and `lot_id` it resolved to (null when nothing
matched), `verdict` (`ok` | `blocked`), `reasons` (jsonb list), `scanned_by`,
`scanned_at`. A line counts as verified when it has an `ok` scan.

### `stock_counts`, `stock_count_lines`

A count: `number` (`SC-yymm-####`), `warehouse_id`, `status` (`counting` |
`submitted` | `approved` | `cancelled`), `notes`, and who started,
submitted, approved or cancelled it and when. Lines: `item_id`, `lot_id`,
`system_quantity` (frozen when the count starts, `0` for a batch found on
the shelf but not in the system), `counted_quantity`, `note`, `counted_by`,
`counted_at`, and `inventory_transaction_id` once the difference is posted.
Unique on `(stock_count_id, item_id, lot_id)`.

### `floor_photos`

A photo taken on the floor against a batch, an order or a count: `subject`
morph, `path` on the local disk, `note`, `taken_by`, `taken_at`.

### Everything else is read, not stored

The reorder advice, slow-moving and expiry-risk reports, the exception
feed, the command centre, the department scorecards and the process
performance table are read at the moment they are asked for; the ERP
assistant reads through the same services and stores nothing. The reorder advice, slow-moving and expiry-risk reports
are read from `stock_balances`, `inventory_lots`, the ledger,
`manufacturing_order_lines`, `production_plan_lines`,
`material_request_lines` and `goods_receipt_lines` at the moment they are
asked for (the dashboard tile caches its count for ten minutes). The
queries are grouped, so the cost is a fixed handful of statements however
many materials there are.

## Dispatch

### `customers`

Who finished goods are billed to and sent to: `code` (`CUS-001`…), `name`,
`legal_name`, `gstin` (unique among live rows), `pan`, `kind` (client,
marketplace, distributor, retailer, other), `client_id` → `clients` (the
contract client whose own goods this customer takes), contact, billing and
shipping addresses, `is_active`, soft deletes.

### `dispatches`, `dispatch_lines`, `dispatch_attachments`

A consignment leaving a finished goods store: `number` (`DSP-yymm-00001`),
`facility_id`, `warehouse_id` (finished goods only), `customer_id`, `status`
(draft → invoiced → dispatched → delivered, or cancelled), `reference`,
the invoice (`invoice_number` — unique among live rows — `invoice_date`,
`place_of_supply` state code, `is_interstate`), a frozen ship-to address,
the e-invoice particulars (`irn` — unique, 64 characters — `ack_number`,
`ack_date`, `signed_qr`), transport (`transporter_name`, `transporter_gstin`,
`vehicle_number`, `lr_number`, `lr_date`, `eway_bill_number`,
`eway_bill_date`, `distance_km`), money to the paisa (`taxable_value`,
`cgst`, `sgst`, `igst`, `other_charges`, `round_off`, `total_value`), and who
wrote it up, invoiced it, dispatched it and marked it delivered.

`dispatch_lines`: `line_no`, `item_id`, `lot_id` (required: goods leave batch
by batch), `uom_id`, `quantity` (> 0), `unit_price`, `discount_percent`,
`hsn_code`, `gst_rate`, `taxable_value`, `cgst`, `sgst`, `igst`,
`line_total`, `description`.

`dispatch_attachments`: `kind` (invoice, signed_invoice, eway_bill, lr,
other), `path`, `original_name`, `mime`, `size`, `uploaded_by`.

The stock leaves through `inventory_transactions` of type `SALES_DISPATCH`
referencing the dispatch, one line per batch, posted only when the goods
are dispatched.

### `facilities.legal_name`

The registered company whose invoices goods leave the site under; printed
as the seller on e-invoices and challans. Falls back to
`ERP_COMPANY_LEGAL_NAME`, then the company name.

## Online orders

### `brands`, `brand_user`

A brand sold online: `code` (`RR`, `CA`), `name`, `legal_name`, `gstin`,
`client_id` → `clients` (null: the company's own stock; set: the parcels
take only that client's batches), `default_warehouse_id` → `warehouses`
(the finished goods store its parcels leave from; seeded to the Paper
Market depot's when that depot exists), `is_active`. `brand_user` gives an
E-commerce Agency account the brands it may upload for.

### `marketplaces`, `marketplace_listings`

`marketplaces`: `code` (`MEESHO`, `FLIPKART`, `AMAZON`, `MYNTRA`), `name`,
`reader` (`meesho`, `flipkart` — read from the label text — or `ai`),
`claim_window_hours`, `is_active`. Seeded by the migration and the
reference data seeder.

`marketplace_listings`: the SKU text a marketplace prints — `marketplace_id`,
`brand_id`, `seller_sku` as printed, `sku_key` (folded for matching; unique
with the marketplace and brand), `is_active`, and `item_id` /
`units_per_order` mirroring its first product.

`marketplace_listing_components`: what one order of a listing holds —
`listing_id`, `item_id`, `units_per_order` (> 0: pieces of that product;
2 for a pack of two), `line_no`; unique per listing and product. One row
for a plain listing, several for a combo.

### `label_batches`, `label_files`, `label_prints`

`label_batches`: one day's labels for one brand on one marketplace from one
store — `number` (`LB-yymm-00001`), `brand_id`, `marketplace_id`,
`facility_id`, `warehouse_id`, `for_date`, `status` (open → closed when the
agency says that is all), `uploaded_by`, `closed_at`, `closed_by`.

`label_files`: the PDFs exactly as uploaded, never rewritten — `path`
(`online-orders/Y/m/{uuid}.pdf` on the private disk), `original_name`,
`size`, `pages`, `sha256` (unique: the same file cannot go in twice),
`read_with` (`meesho`, `flipkart`, `ai`, a combination, or `none`),
`read_model`, `read_at`, `warnings`.

`label_prints`: each print run — `scope` (all, unprinted, courier, one),
`courier`, `shipment_count`, `page_count`, `printed_by`.

### `shipments`, `shipment_lines`

`shipments`: one parcel — `label_batch_id`, `label_file_id`, `pages` (jsonb:
the label page and any invoice pages after it), `marketplace_id`,
`brand_id`, `warehouse_id`, `awb` (unique per marketplace among parcels not
cancelled), `alt_code` (a second barcode, Flipkart's tracking id),
`order_number`, `courier`, `payment_mode` (cod, prepaid, unknown),
`payable_amount`, `invoice_number`, `invoice_date`, `customer_name`,
`customer_state`, `seller_gstin`, `status` (uploaded → printed → packed →
handed_over; or cancelled; or, after packing, returned), `stock_state`
(unmapped, short, reserved, consumed, released, returned), print, pack
(`pack_method` scan or manual, with `pack_note`), handover, cancellation
and `returned_at` stamps, `extraction` (what the reader made of the page),
`warnings`.

`shipment_lines`: the label's rows — `line_no`, `seller_sku`,
`description`, `quantity` (> 0), `listing_id` (null until mapped), and for
a plain listing `item_id` and `units` (quantity × pieces) for display.

`shipment_picks`: the parcel's pick list — `shipment_id`,
`shipment_line_id`, `item_id`, `units` (> 0, in the product's stock unit:
label quantity × pieces of that product). Written when a line is matched
to a listing; a combo line gives one pick per product.

Stock is held through `stock_reservations` (reservable = the shipment), one
per pick, and leaves through `inventory_transactions` of type
`MARKETPLACE_SALE` referencing the shipment, one line per batch, posted
when the parcel is packed. Cancelling a packed parcel posts a `REVERSAL`.

### `shipment_returns`, `shipment_return_lines`

`shipment_returns`: a parcel that came back — `number` (`RT-yymm-00001`),
`shipment_id` (unique: a parcel comes back once), `marketplace_id`,
`brand_id`, `facility_id` (where it was received), `kind` (rto, customer),
`return_awb`, `notes`, `wrong_item`, `claim_status` (none, open, won, lost),
`claim_deadline_at` (received + the marketplace's `claim_window_hours`),
`claim_reference`, `claim_amount`, `claim_note`, `inventory_transaction_id`
(the `MARKETPLACE_RETURN` posting), `received_by`, `received_at`.

`shipment_return_lines`: per product — `item_id`, `sent`, `good`,
`damaged`, `missing` (each ≥ 0, always adding up to `sent`),
`good_warehouse_id`, `damaged_warehouse_id`. Good goods are posted back into
a finished goods store and damaged goods into the facility's `damaged`
store (opened on first use), each into the batches the parcel's stock left
from.

### `handover_sheets`

A courier's pickup: `number` (`HO-yymm-00001`), `facility_id`,
`warehouse_id`, `courier`, `shipment_count`, `received_by_name`,
`handed_over_by`, `handed_over_at`. Shipments point at their sheet.

## Still to come

Zone/rack/bin balances (`stock_balances.location_id` and
`stock_reservations.location_id` exist and are part of the unique key, but
postings are still made at store level), purchase orders and vendor price history, costing (batch, per kilogram, per
unit — built on the consumption each order already records), sales orders and
dispatch, and the approval workflows that will sit on the existing
`approvals` tables. See [DEVELOPMENT_ROADMAP.md](DEVELOPMENT_ROADMAP.md).

The rule that survives all of it: **`stock_quantity` is never an editable
column.** Stock is derived from immutable `inventory_transactions`.
