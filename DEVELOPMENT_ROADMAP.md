# Development roadmap

Where the build has got to, and what comes next.

---

## Done

### Phase 1 — Foundation

| Area                                                                          | State |
| ----------------------------------------------------------------------------- | ----- |
| Laravel 13 + Inertia 3 + React 19 + TypeScript + Tailwind 4                   | Built |
| PostgreSQL as the primary database, for the app and the test suite            | Built |
| Authentication: sign-in, reset, sessions, throttling, 2FA and passkeys wired  | Built |
| Employee accounts: status, department, designation, last sign-in, soft delete | Built |
| Deactivation that ends sessions already open                                  | Built |
| 97 permissions in one catalogue, 16 roles, policy enforcement                 | Built |
| Role editor, audited with before and after                                    | Built |
| Append-only audit trail with an immutability test                             | Built |
| Units of measure and `UnitConversionService`                                  | Built |
| Approval engine schema                                                        | Built |
| Warehouses and locations — full CRUD                                          | Built |
| Raw materials, packaging, products — full CRUD                                | Built |
| Vendors — full CRUD                                                           | Built |
| Users — full CRUD, activate/deactivate, role assignment                       | Built |
| Audit log viewer                                                              | Built |
| Permission-aware navigation and dashboard                                     | Built |
| Shared DataTable: search, sort, filter, paginate, column visibility           | Built |
| Seeders: units, departments, roles, and demo data                             | Built |
| 140 tests against PostgreSQL                                                  | Built |

---

## Next

### Phase 2 — Inventory ledger

The most important thing left, and everything downstream depends on it.

- `inventory_transactions` and `inventory_transaction_lines`, immutable, typed
- `inventory_lots` with expiry and QC state
- `stock_reservations`
- Balances derived from the ledger; cached balances for speed, ledger as truth
- GRN receipt, adjustment, transfer, damage, expiry and sample flows
- Every stock-changing operation inside a database transaction with
  `SELECT … FOR UPDATE`, so two people reserving the same material at the same
  moment cannot both succeed

**Tests this phase is not done without:** stock balance from the ledger, GRN
addition, transfers, adjustments, and two concurrent reservations of the same
stock where exactly one succeeds.

### Phase 3 — Formulations

- `formulas`, `formula_versions`, `formula_ingredients`, `formula_approvals`,
  `formula_access_logs`
- Versioning where a change never overwrites, and one active approved version
- The second-factor unlock specified in
  [SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md#3-formula-protection):
  PIN or password re-entry, short-lived, admin-configurable, throttled, logged
- Formula view recorded as its own audit action

**Tests:** permission enforcement, the second factor, unlock expiry, throttling,
version activation rules, and that formula data appears in no payload an
unauthorised user can reach.

### Phase 4 — Production

- Manufacturing orders against a product and formula version
- `ProductionRequirementService` — requirements, availability, shortages,
  maximum manufacturable quantity, limiting material
- `InventoryReservationService` — reserve on approval, consume on start,
  release on cancellation, handle partial production
- Batch management and traceability

**Tests:** the worked example in
[ERP_PRODUCT_SPEC.md](ERP_PRODUCT_SPEC.md#production--planned) as a test case,
plus reservation lifecycle and batch traceability.

### Phase 5 — Quality control and procurement

- QC on received materials and finished batches; quarantine until release
- Purchase orders with approval, goods receipt into the ledger
- Supplier price history and the price-change dashboards

### Phase 6 — Costing

- `ProductCostingService`: materials, packaging, labour, overhead, wastage
- Batch cost, cost per kilogram, cost per unit
- Historical costing preserved when prices move

### Phase 7 — Sales, marketplace, reporting

- Sales orders and allocation
- Amazon and Flipkart imports, marketplace stock, reconciliation
- Excel import with validation before commit; exports; queued for large files
- Reports and dashboards

### Phase 8 — Operations

- Notifications: in-app first, channels added later
- Scheduler: low stock, expiry, overdue orders, reconciliation, snapshots
- Deployment to a real URL, backups, monitoring

---

## Carried debt

Things deliberately left, so they are not forgotten:

| Item                                  | Why                                                                                                                    | What to do                                                                                                                                       |
| ------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Larastan not installed**            | `phpstan/phpstan` is published only as a pre-built archive from an endpoint the current build environment cannot reach | `composer require --dev larastan/larastan phpstan/phpstan` on a normal network. `phpstan.neon` and the `types:check` script are already in place |
| **Exports not implemented**           | The buttons were removed rather than left inert                                                                        | Build them in phase 7 with `maatwebsite/excel`, queued for large sets                                                                            |
| **Imports not implemented**           | Same                                                                                                                   | Phase 7. Validate fully before committing anything                                                                                               |
| **Item categories have no screen**    | Seeded and selectable, but not yet maintainable in the interface                                                       | Add CRUD when the master-data modules next get attention                                                                                         |
| **Departments have no screen**        | Same                                                                                                                   | Same                                                                                                                                             |
| **Warehouse locations are read-only** | Visible on the warehouse page, seeded, but not editable                                                                | Add when the inventory ledger needs put-away                                                                                                     |
| **Approval engine has no workflows**  | Tables and models exist; no workflow is configured yet                                                                 | Phase 3, starting with formula release                                                                                                           |
| **`user.impersonate` is unused**      | The permission exists; nothing implements it                                                                           | Either build it with full audit logging, or remove it from the catalogue                                                                         |
| **No SSO**                            | Password sign-in first, as specified                                                                                   | Add Socialite drivers for Google Workspace and Microsoft                                                                                         |
| **Redis not yet the default**         | Development uses database drivers                                                                                      | Switch cache, queue and locks to Redis in production — see [DEPLOYMENT.md](DEPLOYMENT.md)                                                        |

---

## How to add a module

The pattern the existing modules follow:

1. **Migration** — `NUMERIC` for anything numeric, `CHECK` constraints for
   anything that must never be nonsense, indexes for anything filtered.
2. **Permissions** — add the module to `PermissionCatalogue`, run
   `php artisan erp:sync-permissions --roles`.
3. **Domain** — model in `app/Domain/<Module>/Models`, with enums, services and
   actions beside it. Business logic here, not in the controller.
4. **Policy** — extend `ModulePolicy`; register it in `AuthServiceProvider`
   (domain models are outside Laravel's discovery path).
5. **Form requests** — one for store, one for update, with the uniqueness rule
   ignoring the record being edited.
6. **Controller** — thin: authorise, validate, delegate.
7. **Factory and seeder.**
8. **Inertia pages** — reuse `DataTable`, `Field`, `FormSection`, `DetailItem`.
9. **Navigation** — add to `erpNavigation` with its permission.
10. **Tests** — the business rules, the permission boundaries, and the failure
    cases. A module is not finished without them.
