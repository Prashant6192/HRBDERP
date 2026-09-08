# Product specification

What HRBD ERP is for, module by module. This describes the whole system,
including the parts not yet built; [DEVELOPMENT_ROADMAP.md](DEVELOPMENT_ROADMAP.md)
says which is which.

---

## The problem

A cosmetics manufacturer runs on facts that live in different heads and
different spreadsheets: how much surfactant is in the store, which version of a
shampoo formula the last batch used, what that batch cost, which supplier
quoted what last quarter, and how much finished stock is sitting in a
marketplace warehouse.

The cost of these being separate is not administrative. It is a production run
halted halfway because a material was short, a batch made to a superseded
formula, and a price rise that nobody noticed until the invoice.

This system holds those facts once, with a record of who changed them.

---

## Principles

**One ledger, derived balances.** Stock is the sum of immutable transactions,
not an editable number. Anything else eventually disagrees with the shelf.

**Formulations are the crown jewels.** Access is permissioned, second-factor
verified, time-limited and logged.

**Every consequential action is attributable.** Who, what, before, after, when.

**Exact arithmetic.** `NUMERIC` columns and decimal maths, never floats.

**The server decides.** The interface hides what a user cannot do; it does not
control it.

---

## Modules

### Authentication and users — _built_

Employee accounts with email, password, department, designation, role and
account status. Sign-in throttling, password reset, session management, and
last-login tracking. Two-factor and passkeys are wired through Fortify;
Google Workspace and Microsoft sign-in can be added without touching
authorisation.

Accounts are deactivated rather than deleted, and deactivation ends any session
already open.

### Roles and permissions — _built_

Sixteen roles, 97 granular permissions, enforced by policies on the server. A
role editor lets an administrator change what a role may do; every change is
audited with the before and after.

See [USER_ROLES_PERMISSIONS.md](USER_ROLES_PERMISSIONS.md).

### Audit log — _built_

An append-only record of consequential actions, readable by those with
`audit.view` and writable by nobody. Filterable by person, action and date.

### Units of measure — _built_

Mass, volume and count, with one base unit each and exact conversion between
them. One service does every conversion. Pack units — carton, box, drum — carry
no size of their own and refuse to convert without a per-item factor, rather
than silently treating a carton as one piece.

### Master data — _built_

**Warehouses** with locations, including quarantine stores whose stock is on the
books but not available to issue.

**Raw materials**, **packaging materials** and **finished goods**: code, name,
category, stock and purchase units, HSN and GST, standard cost, batch and QC
flags, shelf life, and reorder levels. Products additionally carry brand, MRP,
net content and barcode.

**Vendors** with GSTIN, PAN, contact details, payment terms and an approval
flag — a purchase order can only be raised against an approved vendor.

### Approval engine — _schema built, workflows to come_

One engine for the whole ERP: formula releases, purchase orders, production
orders, stock adjustments, write-offs and price overrides. A request, an ordered
set of steps, a record of who did what, and a final state. A step names a
required permission or role; workflows are configuration, not schema.

### Inventory ledger — _next_

The centre of the system.

Immutable `inventory_transactions` in typed pairs: GRN receipt, purchase return,
production reservation, consumption and return, transfer out and in, adjustment
in and out, damage, expiry, sample, sales allocation, marketplace transfer.
Lots, warehouse locations, and stock reservations.

Current stock is derived from the ledger. Cached balances may be maintained for
speed; the ledger remains the truth. Every stock-changing operation runs in a
database transaction with row locking, so that two people reserving the same
material at the same moment cannot both succeed.

### Formulations — _next_

Products have formulas; formulas have versions; versions have ingredients.

A change never overwrites: it creates a new version. Earlier versions are
archived and stay readable. Exactly one approved version is active at a time.

Access requires the permission **and** a second verification that grants a
short-lived unlock. Every view is recorded.

### Production — _planned_

Manufacturing orders against a product and a formula version, for a batch size.

`ProductionRequirementService` answers the question that matters before the
plant starts: _can we make this?_ It calculates ingredient and packaging
requirements, checks available and QC-released stock, and reports shortages, the
maximum manufacturable quantity, and the limiting material.

> Product: Hydra Smooth Shampoo · Formula V3 · Requested 500 KG
>
> | Ingredient   | Required | Available | Shortage |
> | ------------ | -------- | --------- | -------- |
> | Surfactant A | 75 KG    | 100 KG    | —        |
> | Surfactant B | 30 KG    | 18 KG     | 12 KG    |
> | Fragrance    | 2.5 KG   | 5 KG      | —        |
>
> **Production possible:** No · **Maximum:** 300 KG · **Limiting material:**
> Surfactant B · **Shortage:** 12 KG

`InventoryReservationService` reserves rather than deducts when an order is
approved: physical stock stays, available stock falls. Reservations convert to
consumption when production starts, release on cancellation, and handle partial
production.

### Quality control — _planned_

QC on received materials and finished batches. Material requiring QC lands in
quarantine and is unavailable until released. Approvals and rejections are
recorded against the lot.

### Procurement — _planned_

Purchase orders with approval, goods receipt into the ledger, and supplier price
history: material, vendor, price, quantity, effective date, currency and GST.
Dashboards for last, average and previous price, percentage change, cheapest
supplier and price trend.

### Costing — _planned_

`ProductCostingService`: raw material, packaging, labour, overhead, wastage and
configurable costs, producing batch cost, cost per kilogram and cost per unit.

Historical costing is preserved: a material price rise never rewrites what an
earlier batch cost.

### Sales and marketplace — _planned_

Sales orders and allocation from finished stock. Marketplace imports for Amazon
and Flipkart, stock transfers to fulfilment centres, and reconciliation.

### Reporting, imports and exports — _planned_

Excel import for opening stock, masters, vendor prices and marketplace sales,
validated before anything is committed: total rows, valid rows, invalid rows and
the specific errors, so a bad file can be fixed rather than half-imported.
Exports for the stock ledger, inventory, purchase orders, production, sales and
costing. Large files run on the queue.

### Notifications — _planned_

In-app first, through Laravel's notification system so email, WhatsApp, SMS and
Slack can be added later as channels without touching the business logic.

### Scheduled work — _planned_

Daily low-stock scan, expiry alerts, overdue purchase orders, marketplace
reconciliation, price-change alerts, production delays, inventory snapshots and
scheduled reports.

---

## Interface

Desktop first, then laptop, tablet and mobile. Factory and warehouse staff need
to record receipts, transfers and consumption from a tablet on the floor, so
those actions work at every width.

Every list shares one component: search, sort, filter, paginate, choose columns.
Every figure a user is not entitled to see is absent from the payload, not
merely hidden.
