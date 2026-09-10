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
| Tests against PostgreSQL from the first commit                                | Built |

---

### Phases A–F — The factory, end to end

| Area                                                                                              | State |
| ------------------------------------------------------------------------------------------------- | ----- |
| Immutable inventory ledger, lots, cached balances, reservations, gap-free document numbers        | Built |
| Row-locked postings, proven with forked processes against PostgreSQL                              | Built |
| Stock alert levels per item: Moderate / Low / Critically low / Out of stock; expiring-soon        | Built |
| Goods receipts → quarantine → QC approve / reject / hold → store; printable batch sticker         | Built |
| Formulations: versions with one active recipe (database-enforced), draft editing, archive, delete | Built |
| Formula PIN: hashed, 5–60 min unlock bound to the session, lockout, append-only access trail      | Built |
| Formulation Excel import for both layouts chemists use, with a plan to confirm; console command   | Built |
| Recipe scaling to any batch in stock units, density fallback flagged                              | Built |
| Production plans checked against both stores; Production Material Requests per store; PMR PDF     | Built |
| Deliveries booked in against a PMR close its lines                                                | Built |
| Packaging-per-unit list on products                                                               | Built |
| Manufacturing orders: approve → reserve, start → consume, complete → finished lot (QC), cancel    | Built |
| Navigation in workflow order; dashboard with charts, store donuts, production stage, alerts       | Built |
| Additive permission sync: new abilities reach the right roles without undoing Roles-screen edits  | Built |
| 280+ tests against PostgreSQL                                                                     | Built |

---

## Next

### Phase 6 — Costing

- `ProductCostingService`: materials (from the consumption each order records),
  packaging, labour, overhead, wastage
- Batch cost, cost per kilogram, cost per unit; historical costing preserved
- Vendor price history and the price-change dashboards

### Phase 7 — Purchase orders

- Purchase orders raised from material requests, with approval
- Goods receipts against purchase orders (they already close material requests)
- Overdue-order alerts

### Phase 8 — Accounting, dispatch and sales

- Sales orders and allocation from finished stock; dispatch notes
- Amazon and Flipkart imports, marketplace stock, reconciliation
- Accounting summaries: purchases, production cost, sales

### Phase 9 — Approvals, notifications, scheduler

- Approval workflows on the existing engine: formula activation, plan
  approval, order approval, stock adjustments
- Notifications in-app first; channels added later
- Scheduler: low stock, expiry, overdue requests, snapshots

### Phase 10 — Imports, exports, HR

- Excel import for opening stock and masters, validated before commit; exports
  for ledger, stock, purchases, production; queued for large files
- Departments and employee records beyond accounts

---

## Carried debt

Things deliberately left, so they are not forgotten:

| Item                                   | Why                                                                                                                    | What to do                                                                                                                                       |
| -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Larastan not installed**             | `phpstan/phpstan` is published only as a pre-built archive from an endpoint the current build environment cannot reach | `composer require --dev larastan/larastan phpstan/phpstan` on a normal network. `phpstan.neon` and the `types:check` script are already in place |
| **Exports not implemented**            | The buttons were removed rather than left inert                                                                        | Phase 10 with `maatwebsite/excel`, queued for large sets                                                                                         |
| **Only the formulation import exists** | Opening stock, masters and price imports are not yet built                                                             | Phase 10. Validate fully before committing anything, as the formulation import does                                                              |
| **Approvals not yet on the engine**    | Formula activation and order approval are single-permission actions                                                    | Phase 9: route them through `approvals` so multi-step sign-off is configuration                                                                  |
| **Density is assumed where missing**   | A mass↔volume conversion for a material with no density is planned at 1 g/ml and flagged                               | Enter densities on the raw materials that matter; the flag disappears when the figure is real                                                    |
| **Item categories have no screen**     | Seeded and selectable, but not yet maintainable in the interface                                                       | Add CRUD when the master-data modules next get attention                                                                                         |
| **Departments have no screen**         | Same                                                                                                                   | Same                                                                                                                                             |
| **Warehouse locations are read-only**  | Visible on the warehouse page, seeded, but not editable                                                                | Add when the inventory ledger needs put-away                                                                                                     |
| **`user.impersonate` is unused**       | The permission exists; nothing implements it                                                                           | Either build it with full audit logging, or remove it from the catalogue                                                                         |
| **No SSO**                             | Password sign-in first, as specified                                                                                   | Add Socialite drivers for Google Workspace and Microsoft                                                                                         |
| **Redis not yet the default**          | Development uses database drivers                                                                                      | Switch cache, queue and locks to Redis in production — see [DEPLOYMENT.md](DEPLOYMENT.md)                                                        |

---

## How to add a module

The pattern the existing modules follow:

1. **Migration** — `NUMERIC` for anything numeric, `CHECK` constraints for
   anything that must never be nonsense, indexes for anything filtered.
2. **Permissions** — add the module to `PermissionCatalogue` and its
   abilities to the roles in `RoleName`; `php artisan erp:sync-permissions`
   (which every deploy runs) hands the new abilities to those roles.
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
