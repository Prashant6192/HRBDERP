# Users, roles and permissions

Sixteen roles, 97 permissions. This document is generated from the code in
`app/Domain/Access` — if it disagrees with the application, the code is right
and this file needs regenerating.

---

## How permissions are named

Every permission is `<module>.<ability>`: `inventory.receive`,
`formula.approve`, `raw_material.import`.

Module keys are singular throughout (`report.export`, not `reports.export`), so
that a permission name can always be derived from its module without
remembering exceptions.

The catalogue in `App\Domain\Access\PermissionCatalogue` is the single source of
truth. Adding an ability is a one-line change there, followed by:

```bash
php artisan erp:sync-permissions
```

That creates any new permission. It never deletes: a permission removed from the
catalogue is reported, not dropped out from under whoever currently holds it.
Add `--prune` to remove them deliberately, and `--roles` to reset the built-in
roles to the defaults below.

---

## The modules

### Administration

| Module              | Key          | Abilities                                                   |
| ------------------- | ------------ | ----------------------------------------------------------- |
| Users               | `user`       | `view`, `create`, `edit`, `delete`, `export`, `impersonate` |
| Roles & Permissions | `role`       | `view`, `create`, `edit`, `delete`                          |
| Departments         | `department` | `view`, `create`, `edit`, `delete`                          |
| Audit Log           | `audit`      | `view`, `export`                                            |
| Settings            | `setting`    | `view`, `edit`                                              |

### Master Data

| Module              | Key                  | Abilities                                              |
| ------------------- | -------------------- | ------------------------------------------------------ |
| Warehouses          | `warehouse`          | `view`, `create`, `edit`, `delete`, `export`           |
| Product Master      | `product`            | `view`, `create`, `edit`, `delete`, `export`, `import` |
| Raw Materials       | `raw_material`       | `view`, `create`, `edit`, `delete`, `export`, `import` |
| Packaging Materials | `packaging_material` | `view`, `create`, `edit`, `delete`, `export`, `import` |
| Vendors             | `vendor`             | `view`, `create`, `edit`, `delete`, `export`           |
| Units of Measure    | `uom`                | `view`, `create`, `edit`, `delete`                     |

### Operations

| Module          | Key          | Abilities                                                               |
| --------------- | ------------ | ----------------------------------------------------------------------- |
| Inventory       | `inventory`  | `view`, `receive`, `adjust`, `transfer`, `reserve`, `consume`, `export` |
| Formulations    | `formula`    | `view`, `create`, `edit`, `approve`, `archive`, `export`                |
| Production      | `production` | `view`, `create`, `edit`, `approve`, `consume`, `cancel`, `export`      |
| Procurement     | `purchase`   | `view`, `create`, `edit`, `approve`, `receive`, `export`                |
| Quality Control | `qc`         | `view`, `create`, `approve`, `reject`, `export`                         |

### Commercial

| Module      | Key           | Abilities                                    |
| ----------- | ------------- | -------------------------------------------- |
| Sales       | `sales`       | `view`, `create`, `edit`, `delete`, `export` |
| Marketplace | `marketplace` | `view`, `import`, `reconcile`, `export`      |
| Costing     | `costing`     | `view`, `edit`, `export`                     |
| Reports     | `report`      | `view`, `export`                             |

### Workflow

| Module    | Key        | Abilities     |
| --------- | ---------- | ------------- |
| Approvals | `approval` | `view`, `act` |

---

## How roles work

A role is a named bundle of permissions. A user may hold more than one, and
holds the union of their permissions.

Role definitions use wildcards — a role granted `inventory.*` picks up any
ability added to that module later, so a new ability does not silently go
ungranted.

### Super Admin

Super Admin passes every check through a `Gate::before` hook rather than
holding rows. **With one exception:** it does not bypass a check about its own
account. A Super Admin still cannot delete themselves, deactivate themselves,
or grant themselves a role — see
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md#the-super-admin-bypass-and-its-one-exception).

### Who may hand out roles

**Only a Super Admin, and never to themselves.** Role assignment is the one
permission that can be used to grant every other, so it is gated more tightly
than `user.edit`.

### Formulations

Eight roles are deliberately excluded from `formula.view`: Designer, Marketing
Manager, E-commerce Manager, Sales Manager, Brand Manager, Warehouse Manager,
Accounts Manager and Viewer. A test asserts this, so removing the restriction
has to be a deliberate act rather than an accident.

Holding `formula.view` is still not enough on its own — a second verification
is required. See
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md#3-formula-protection).

### Who owns the material masters

Procurement. A purchase manager onboards a new raw material or component when
they source it, so they hold create, edit, import and export on both material
masters. Deletion is withheld: a material with purchase history is deactivated,
not removed.

The factory manager reads those masters and does not maintain them.

---

## The roles

### Super Admin

Unrestricted access, including roles, permissions and settings. Reserved for the system administrator.

Holds **every permission** (97).

### Owner

Full visibility of the business including formulations, costing and every report.

Holds **every permission** (97).

### Director

Board-level oversight with approval authority across production, procurement and formulations.

| Module               | Abilities                     |
| -------------------- | ----------------------------- |
| `approval`           | view, act                     |
| `audit`              | view, export                  |
| `costing`            | view, export                  |
| `department`         | view                          |
| `formula`            | view, approve, export         |
| `inventory`          | view, export                  |
| `marketplace`        | view                          |
| `packaging_material` | view                          |
| `product`            | view                          |
| `production`         | view, approve, cancel, export |
| `purchase`           | view, approve, export         |
| `qc`                 | view                          |
| `raw_material`       | view                          |
| `report`             | view, export                  |
| `sales`              | view, export                  |
| `uom`                | view                          |
| `user`               | view                          |
| `vendor`             | view                          |
| `warehouse`          | view                          |

_32 permissions._

### Management

Cross-department visibility and approval authority, without administration rights.

| Module               | Abilities             |
| -------------------- | --------------------- |
| `approval`           | view, act             |
| `audit`              | view                  |
| `costing`            | view                  |
| `department`         | view                  |
| `formula`            | view                  |
| `inventory`          | view, export          |
| `marketplace`        | view                  |
| `packaging_material` | view                  |
| `product`            | view                  |
| `production`         | view, approve, export |
| `purchase`           | view, approve, export |
| `qc`                 | view                  |
| `raw_material`       | view                  |
| `report`             | view, export          |
| `sales`              | view                  |
| `uom`                | view                  |
| `user`               | view                  |
| `vendor`             | view                  |
| `warehouse`          | view                  |

_26 permissions._

### Factory Manager

Runs the plant: production, inventory, quality and the formulations needed to manufacture.

| Module               | Abilities                                                 |
| -------------------- | --------------------------------------------------------- |
| `approval`           | view, act                                                 |
| `costing`            | view                                                      |
| `formula`            | view, export                                              |
| `inventory`          | view, receive, adjust, transfer, reserve, consume, export |
| `packaging_material` | view                                                      |
| `product`            | view                                                      |
| `production`         | view, create, edit, approve, consume, cancel, export      |
| `purchase`           | view, create                                              |
| `qc`                 | view, create, approve, reject                             |
| `raw_material`       | view                                                      |
| `report`             | view, export                                              |
| `uom`                | view                                                      |
| `vendor`             | view                                                      |
| `warehouse`          | view                                                      |

_33 permissions._

### Production Manager

Creates and runs manufacturing orders and consumes materials against them.

| Module               | Abilities                           |
| -------------------- | ----------------------------------- |
| `approval`           | view                                |
| `formula`            | view                                |
| `inventory`          | view, reserve, consume              |
| `packaging_material` | view                                |
| `product`            | view                                |
| `production`         | view, create, edit, consume, export |
| `qc`                 | view                                |
| `raw_material`       | view                                |
| `report`             | view                                |
| `uom`                | view                                |
| `warehouse`          | view                                |

_17 permissions._

### Warehouse Manager

Receives, adjusts and transfers stock, and maintains warehouse master data.

| Module               | Abilities                               |
| -------------------- | --------------------------------------- |
| `approval`           | view                                    |
| `inventory`          | view, receive, adjust, transfer, export |
| `packaging_material` | view                                    |
| `product`            | view                                    |
| `production`         | view                                    |
| `purchase`           | view, receive                           |
| `qc`                 | view                                    |
| `raw_material`       | view                                    |
| `report`             | view, export                            |
| `uom`                | view                                    |
| `warehouse`          | view, create, edit, delete, export      |

_21 permissions._

### Purchase Manager

Raises and approves purchase orders and maintains vendors and prices.

| Module               | Abilities                                    |
| -------------------- | -------------------------------------------- |
| `approval`           | view, act                                    |
| `costing`            | view                                         |
| `inventory`          | view                                         |
| `packaging_material` | view, create, edit, export, import           |
| `product`            | view                                         |
| `purchase`           | view, create, edit, approve, receive, export |
| `raw_material`       | view, create, edit, export, import           |
| `report`             | view, export                                 |
| `uom`                | view                                         |
| `vendor`             | view, create, edit, delete, export           |
| `warehouse`          | view                                         |

_30 permissions._

### QC Manager

Approves or rejects material and batch quality, and holds stock from release.

| Module               | Abilities                             |
| -------------------- | ------------------------------------- |
| `approval`           | view, act                             |
| `formula`            | view                                  |
| `inventory`          | view                                  |
| `packaging_material` | view                                  |
| `product`            | view                                  |
| `production`         | view                                  |
| `purchase`           | view                                  |
| `qc`                 | view, create, approve, reject, export |
| `raw_material`       | view                                  |
| `report`             | view, export                          |
| `uom`                | view                                  |
| `vendor`             | view                                  |
| `warehouse`          | view                                  |

_19 permissions._

### Accounts Manager

Costing, pricing and financial reporting.

| Module               | Abilities          |
| -------------------- | ------------------ |
| `approval`           | view               |
| `costing`            | view, edit, export |
| `inventory`          | view, export       |
| `marketplace`        | view               |
| `packaging_material` | view               |
| `product`            | view               |
| `production`         | view               |
| `purchase`           | view, export       |
| `raw_material`       | view               |
| `report`             | view, export       |
| `sales`              | view, export       |
| `uom`                | view               |
| `vendor`             | view               |
| `warehouse`          | view               |

_20 permissions._

### Marketing Manager

Brand, product presentation and marketing reporting.

| Module        | Abilities    |
| ------------- | ------------ |
| `marketplace` | view         |
| `product`     | view, edit   |
| `report`      | view, export |
| `sales`       | view, export |

_7 permissions._

### E-commerce Manager

Marketplace listings, imports and reconciliation.

| Module        | Abilities                       |
| ------------- | ------------------------------- |
| `inventory`   | view                            |
| `marketplace` | view, import, reconcile, export |
| `product`     | view                            |
| `report`      | view, export                    |
| `sales`       | view, create, edit, export      |

_12 permissions._

### Sales Manager

Sales orders, customers and sales reporting.

| Module        | Abilities                          |
| ------------- | ---------------------------------- |
| `inventory`   | view                               |
| `marketplace` | view                               |
| `product`     | view                               |
| `report`      | view, export                       |
| `sales`       | view, create, edit, delete, export |

_10 permissions._

### Brand Manager

Product master and brand-level reporting.

| Module               | Abilities                  |
| -------------------- | -------------------------- |
| `marketplace`        | view                       |
| `packaging_material` | view                       |
| `product`            | view, create, edit, export |
| `report`             | view                       |
| `sales`              | view                       |

_8 permissions._

### Designer

Packaging artwork and product imagery. No access to formulations or costing.

| Module               | Abilities |
| -------------------- | --------- |
| `packaging_material` | view      |
| `product`            | view      |

_2 permissions._

### Viewer

Read-only access to operational data. No formulations.

| Module               | Abilities |
| -------------------- | --------- |
| `inventory`          | view      |
| `packaging_material` | view      |
| `product`            | view      |
| `production`         | view      |
| `purchase`           | view      |
| `qc`                 | view      |
| `raw_material`       | view      |
| `report`             | view      |
| `sales`              | view      |
| `uom`                | view      |
| `vendor`             | view      |
| `warehouse`          | view      |

_12 permissions._

---

## Changing a role

Two ways, and they behave differently:

| Route                                          | Effect                                                                                                                     |
| ---------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| **Roles screen** (Administration → Roles)      | Edits the live role. Takes effect immediately. Recorded in the audit trail with the full before and after permission sets. |
| **`php artisan erp:sync-permissions --roles`** | Resets every built-in role to the defaults above, discarding changes made in the screen.                                   |

Use the screen for real changes. Use the command after adding a module, or to
return to a known state.

Roles you create yourself are never touched by the command — it only resets the
sixteen built-in ones.
