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

### `warehouses`, `warehouse_locations`

A location code is unique **within** its warehouse, not globally — every
warehouse is entitled to a rack called `A-01`. Stock in a warehouse flagged
`is_quarantine` is on the books but cannot be issued to production or sales
until QC releases it.

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

## Still to come

The inventory ledger is the next and most important addition. Its shape is
already decided — see [DEVELOPMENT_ROADMAP.md](DEVELOPMENT_ROADMAP.md) — and the
principle is that **`stock_quantity` is never an editable column.** Stock is
derived from immutable `inventory_transactions`; cached balances may exist for
speed, but the ledger is the truth.
