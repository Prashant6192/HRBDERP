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

| Module              | Key          | Abilities                                                                    |
| ------------------- | ------------ | ---------------------------------------------------------------------------- |
| Inventory           | `inventory`  | `view`, `receive`, `adjust`, `transfer`, `reserve`, `consume`, `export`      |
| Formulations        | `formula`    | `view`, `create`, `edit`, `delete`, `approve`, `archive`, `import`, `export` |
| Planning & Purchase | `planning`   | `view`, `create`, `edit`, `approve`, `cancel`, `export`                      |
| Production          | `production` | `view`, `create`, `edit`, `approve`, `consume`, `cancel`, `export`           |
| Procurement         | `purchase`   | `view`, `create`, `edit`, `approve`, `receive`, `export`                     |
| Quality Control     | `qc`         | `view`, `create`, `approve`, `reject`, `export`                              |

Where each ability bites:

- `formula.view` opens the list of formula names; seeing a recipe also needs the
  formula PIN — see [SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md#3-formula-protection).
- `formula.approve` activates a version; `formula.import` uploads a workbook.
- `planning.create` raises a production plan and its material requests;
  `planning.approve` and `planning.cancel` are the Director's.
- `production.approve` holds the materials for a manufacturing order;
  `production.consume` starts and completes a batch; `production.cancel` lets
  them go.
- `purchase.receive` books deliveries in; `purchase.view` also opens the
  material requests the stores and purchase work from.
- `qc.approve` and `qc.reject` decide a batch in quarantine.

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

Generated from `RoleName::permissions()` — the code is the source of truth,
and a deploy hands each built-in role any ability that is new to the
catalogue without disturbing what an administrator has changed on the
Roles screen.

### Super Admin

Unrestricted access, including roles, permissions and settings. Reserved for the system administrator.

| Module | Abilities |
| ------ | --------- |
| Users | `view`, `create`, `edit`, `delete`, `export`, `impersonate` |
| Roles & Permissions | `view`, `create`, `edit`, `delete` |
| Departments | `view`, `create`, `edit`, `delete` |
| Audit Log | `view`, `export` |
| Settings | `view`, `edit` |
| Warehouses | `view`, `create`, `edit`, `delete`, `export` |
| Product Master | `view`, `create`, `edit`, `delete`, `export`, `import` |
| Raw Materials | `view`, `create`, `edit`, `delete`, `export`, `import` |
| Packaging Materials | `view`, `create`, `edit`, `delete`, `export`, `import` |
| Vendors | `view`, `create`, `edit`, `delete`, `export` |
| Units of Measure | `view`, `create`, `edit`, `delete` |
| Inventory | `view`, `receive`, `adjust`, `transfer`, `reserve`, `consume`, `export` |
| Formulations | `view`, `create`, `edit`, `delete`, `approve`, `archive`, `import`, `export` |
| Planning & Purchase | `view`, `create`, `edit`, `approve`, `cancel`, `export` |
| Production | `view`, `create`, `edit`, `approve`, `consume`, `cancel`, `export` |
| Procurement | `view`, `create`, `edit`, `approve`, `receive`, `export` |
| Quality Control | `view`, `create`, `approve`, `reject`, `export` |
| Sales | `view`, `create`, `edit`, `delete`, `export` |
| Marketplace | `view`, `import`, `reconcile`, `export` |
| Costing | `view`, `edit`, `export` |
| Reports | `view`, `export` |
| Approvals | `view`, `act` |

### Owner

Full visibility of the business including formulations, costing and every report.

| Module | Abilities |
| ------ | --------- |
| Users | `view`, `create`, `edit`, `delete`, `export`, `impersonate` |
| Roles & Permissions | `view`, `create`, `edit`, `delete` |
| Departments | `view`, `create`, `edit`, `delete` |
| Audit Log | `view`, `export` |
| Settings | `view`, `edit` |
| Warehouses | `view`, `create`, `edit`, `delete`, `export` |
| Product Master | `view`, `create`, `edit`, `delete`, `export`, `import` |
| Raw Materials | `view`, `create`, `edit`, `delete`, `export`, `import` |
| Packaging Materials | `view`, `create`, `edit`, `delete`, `export`, `import` |
| Vendors | `view`, `create`, `edit`, `delete`, `export` |
| Units of Measure | `view`, `create`, `edit`, `delete` |
| Inventory | `view`, `receive`, `adjust`, `transfer`, `reserve`, `consume`, `export` |
| Formulations | `view`, `create`, `edit`, `delete`, `approve`, `archive`, `import`, `export` |
| Planning & Purchase | `view`, `create`, `edit`, `approve`, `cancel`, `export` |
| Production | `view`, `create`, `edit`, `approve`, `consume`, `cancel`, `export` |
| Procurement | `view`, `create`, `edit`, `approve`, `receive`, `export` |
| Quality Control | `view`, `create`, `approve`, `reject`, `export` |
| Sales | `view`, `create`, `edit`, `delete`, `export` |
| Marketplace | `view`, `import`, `reconcile`, `export` |
| Costing | `view`, `edit`, `export` |
| Reports | `view`, `export` |
| Approvals | `view`, `act` |

### Director

Board-level oversight with approval authority across production, procurement and formulations.

| Module | Abilities |
| ------ | --------- |
| Users | `view` |
| Departments | `view` |
| Audit Log | `view`, `export` |
| Warehouses | `view` |
| Product Master | `view` |
| Raw Materials | `view` |
| Packaging Materials | `view` |
| Vendors | `view` |
| Units of Measure | `view` |
| Inventory | `view`, `export` |
| Formulations | `view`, `approve`, `export` |
| Planning & Purchase | `view`, `approve`, `cancel`, `export` |
| Production | `view`, `approve`, `cancel`, `export` |
| Procurement | `view`, `approve`, `export` |
| Quality Control | `view` |
| Sales | `view`, `export` |
| Marketplace | `view` |
| Costing | `view`, `export` |
| Reports | `view`, `export` |
| Approvals | `view`, `act` |

### Management

Cross-department visibility and approval authority, without administration rights.

| Module | Abilities |
| ------ | --------- |
| Users | `view` |
| Departments | `view` |
| Audit Log | `view` |
| Warehouses | `view` |
| Product Master | `view` |
| Raw Materials | `view` |
| Packaging Materials | `view` |
| Vendors | `view` |
| Units of Measure | `view` |
| Inventory | `view`, `export` |
| Formulations | `view` |
| Planning & Purchase | `view`, `approve` |
| Production | `view`, `approve`, `export` |
| Procurement | `view`, `approve`, `export` |
| Quality Control | `view` |
| Sales | `view` |
| Marketplace | `view` |
| Costing | `view` |
| Reports | `view`, `export` |
| Approvals | `view`, `act` |

### Factory Manager

Runs the plant: production, inventory, quality and the formulations needed to manufacture.

| Module | Abilities |
| ------ | --------- |
| Warehouses | `view` |
| Product Master | `view` |
| Raw Materials | `view` |
| Packaging Materials | `view` |
| Vendors | `view` |
| Units of Measure | `view` |
| Inventory | `view`, `receive`, `adjust`, `transfer`, `reserve`, `consume`, `export` |
| Formulations | `view`, `create`, `edit`, `import`, `export` |
| Planning & Purchase | `view`, `create`, `edit`, `approve`, `cancel`, `export` |
| Production | `view`, `create`, `edit`, `approve`, `consume`, `cancel`, `export` |
| Procurement | `view`, `create` |
| Quality Control | `view`, `create`, `approve`, `reject` |
| Costing | `view` |
| Reports | `view`, `export` |
| Approvals | `view`, `act` |

### Production Manager

Creates and runs manufacturing orders and consumes materials against them.

| Module | Abilities |
| ------ | --------- |
| Warehouses | `view` |
| Product Master | `view` |
| Raw Materials | `view` |
| Packaging Materials | `view` |
| Units of Measure | `view` |
| Inventory | `view`, `reserve`, `consume` |
| Formulations | `view` |
| Planning & Purchase | `view`, `create`, `edit`, `export` |
| Production | `view`, `create`, `edit`, `consume`, `export` |
| Quality Control | `view` |
| Reports | `view` |
| Approvals | `view` |

### Warehouse Manager

Receives, adjusts and transfers stock, and maintains warehouse master data.

| Module | Abilities |
| ------ | --------- |
| Warehouses | `view`, `create`, `edit`, `delete`, `export` |
| Product Master | `view` |
| Raw Materials | `view` |
| Packaging Materials | `view` |
| Units of Measure | `view` |
| Inventory | `view`, `receive`, `adjust`, `transfer`, `export` |
| Planning & Purchase | `view` |
| Production | `view` |
| Procurement | `view`, `receive` |
| Quality Control | `view` |
| Reports | `view`, `export` |
| Approvals | `view` |

### Purchase Manager

Raises and approves purchase orders and maintains vendors and prices.

| Module | Abilities |
| ------ | --------- |
| Warehouses | `view` |
| Product Master | `view` |
| Raw Materials | `view`, `create`, `edit`, `export`, `import` |
| Packaging Materials | `view`, `create`, `edit`, `export`, `import` |
| Vendors | `view`, `create`, `edit`, `delete`, `export` |
| Units of Measure | `view` |
| Inventory | `view` |
| Planning & Purchase | `view`, `export` |
| Procurement | `view`, `create`, `edit`, `approve`, `receive`, `export` |
| Costing | `view` |
| Reports | `view`, `export` |
| Approvals | `view`, `act` |

### QC Manager

Approves or rejects material and batch quality, and holds stock from release.

| Module | Abilities |
| ------ | --------- |
| Warehouses | `view` |
| Product Master | `view` |
| Raw Materials | `view` |
| Packaging Materials | `view` |
| Vendors | `view` |
| Units of Measure | `view` |
| Inventory | `view` |
| Formulations | `view` |
| Production | `view` |
| Procurement | `view` |
| Quality Control | `view`, `create`, `approve`, `reject`, `export` |
| Reports | `view`, `export` |
| Approvals | `view`, `act` |

### Accounts Manager

Costing, pricing and financial reporting.

| Module | Abilities |
| ------ | --------- |
| Warehouses | `view` |
| Product Master | `view` |
| Raw Materials | `view` |
| Packaging Materials | `view` |
| Vendors | `view` |
| Units of Measure | `view` |
| Inventory | `view`, `export` |
| Production | `view` |
| Procurement | `view`, `export` |
| Sales | `view`, `export` |
| Marketplace | `view` |
| Costing | `view`, `edit`, `export` |
| Reports | `view`, `export` |
| Approvals | `view` |

### Marketing Manager

Brand, product presentation and marketing reporting.

| Module | Abilities |
| ------ | --------- |
| Product Master | `view`, `edit` |
| Sales | `view`, `export` |
| Marketplace | `view` |
| Reports | `view`, `export` |

### E-commerce Manager

Marketplace listings, imports and reconciliation.

| Module | Abilities |
| ------ | --------- |
| Product Master | `view` |
| Inventory | `view` |
| Sales | `view`, `create`, `edit`, `export` |
| Marketplace | `view`, `import`, `reconcile`, `export` |
| Reports | `view`, `export` |

### Sales Manager

Sales orders, customers and sales reporting.

| Module | Abilities |
| ------ | --------- |
| Product Master | `view` |
| Inventory | `view` |
| Sales | `view`, `create`, `edit`, `delete`, `export` |
| Marketplace | `view` |
| Reports | `view`, `export` |

### Brand Manager

Product master and brand-level reporting.

| Module | Abilities |
| ------ | --------- |
| Product Master | `view`, `create`, `edit`, `export` |
| Packaging Materials | `view` |
| Sales | `view` |
| Marketplace | `view` |
| Reports | `view` |

### Designer

Packaging artwork and product imagery. No access to formulations or costing.

| Module | Abilities |
| ------ | --------- |
| Product Master | `view` |
| Packaging Materials | `view` |

### Viewer

Read-only access to operational data. No formulations.

| Module | Abilities |
| ------ | --------- |
| Warehouses | `view` |
| Product Master | `view` |
| Raw Materials | `view` |
| Packaging Materials | `view` |
| Vendors | `view` |
| Units of Measure | `view` |
| Inventory | `view` |
| Production | `view` |
| Procurement | `view` |
| Quality Control | `view` |
| Sales | `view` |
| Reports | `view` |

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
