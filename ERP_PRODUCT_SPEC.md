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

### Inventory ledger — _built_

The centre of the system.

Every movement is a row in `inventory_transactions` with typed lines: goods
receipt, QC release and rejection, production consumption and output, transfer,
adjustment, damage, expiry, sample, dispatch. Nothing is ever edited; a
correction is another movement. `stock_balances` is a cache the ledger can
rebuild at any time.

Each store shows what is on hand, held for production, and free, with a level
per material from its own thresholds — **Moderate**, **Low**, **Critically
low**, **Out of stock** — and a count of batches expiring soon. Every
stock-changing operation runs in a database transaction with row locks, proven
by a test that forks real processes against PostgreSQL: two people reserving
the same drum at the same moment cannot both succeed.

### Receiving and quality control — _built_

A delivery is booked in from the delivery note and posted. Every line becomes a
numbered batch (`RM250909-001`). Material that needs QC lands in the quarantine
store and opens an inspection; material that does not goes straight to its
store. QC approves (the whole batch moves to the store), rejects (it stays
locked in quarantine) or holds. Every approved batch has a 100 × 70 mm
sticker — QC APPROVED, batch number, received / manufactured / expiry, quantity
— for a label printer. Finished batches from production go through the same
checkpoint.

### Formulations — _built_

Products have formulas; formulas have versions; versions have ingredients as
percentages of a reference batch, with an optional QS line for the filler.

A change never overwrites: it creates a draft version which, on activation,
supersedes the previous one. Exactly one active version exists per formula —
the database enforces it. Production orders record the version they were made
from.

The list shows names only. Opening, editing, scaling or importing a recipe
requires the formula PIN, which grants a short unlock bound to the browser
session. Every attempt and every look is recorded in the formula access trail.
See [SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md#3-formula-protection).

Recipes arrive by hand or from Excel: the import understands the workbooks
chemists actually write — a column table of INCI, trade name and %, or one cell
per line read top to bottom — and shows the full plan (new formulas, new
versions, raw materials to create, everything it had to guess at) before
writing anything.

### Planning & Purchase — _built_

The manufacturing head picks a formula and a batch size. The plan is checked
against the stores as it is saved: every raw material scaled to the batch in
its stock unit, set against what the raw material store can release for
production — required, in store, short, and the store's alert level now and
after the run. Packaging is planned from the product's per-unit pack list and
net content. Warnings say what could not be decided (no density, no pack list,
no product linked).

Raising requests produces one **Production Material Request** per store —
raw material and packaging — with the shortfall to order and the quantity that
also restores the reorder level, printable for the store and purchase.
Deliveries booked in against a request close its lines as stock lands.

> Plan PLN-2609-00001 · Brightening Body Lotion · 25 kg
>
> | Material         | Required | In store | Short    | Level          |
> | ---------------- | -------- | -------- | -------- | -------------- |
> | Purified Water   | 18.85 kg | 0 kg     | 18.85 kg | Out → Out      |
> | Glycerin         | 0.5 kg   | 2.1 kg   | —        | Low → Critical |
>
> Two requests raised: PMR-2609-00001 (raw material), PMR-2609-00002 (packaging).

### Manufacturing — _built_

A manufacturing order is opened from a checked plan. **Approve** holds every
material in its store, earliest-expiring batches first, all or nothing — if
anything is short the message names each shortfall. **Start** issues the raw
materials to the kettle through the ledger. **Complete** records output, units
packed and yield, uses up the packaging, releases anything left and posts the
finished batch as a lot, into quarantine for QC. **Cancel** releases what is
held; what the kettle took stays taken.

### Procurement — _partly built_

Goods receipts, vendors and material requests are built. Purchase orders with
approval, and supplier price history with its dashboards (last, average,
previous, change, cheapest supplier, trend), are next.

### Dashboard — _built_

A greeting with the day's headlines; in production, plans awaiting production,
open material requests, awaiting QC, critically low materials, expiring
batches; each store's items by alert level; what is on the floor and at which
stage; receiving and QC per day over 7 / 30 / 90 days; units packed per week;
materials to watch; what is coming up. Every card is gated by the permission of
the module behind it, and each person can hide the ones they do not need.

### Costing — _planned_

`ProductCostingService`: raw material, packaging, labour, overhead, wastage and
configurable costs, producing batch cost, cost per kilogram and cost per unit.
The consumption each manufacturing order already records is its input.

Historical costing is preserved: a material price rise never rewrites what an
earlier batch cost.

### Accounting, dispatch and sales — _planned_

Sales orders and allocation from finished stock; dispatch notes; marketplace
imports for Amazon and Flipkart, stock transfers to fulfilment centres, and
reconciliation. Shown in the navigation now so the shape of the system is
visible.

### Reporting, imports and exports — _partly built_

The formulation import is built. Excel import for opening stock, masters,
vendor prices and marketplace sales, validated before anything is committed,
and exports for the stock ledger, inventory, purchases, production, sales and
costing, are next. Large files will run on the queue.

### Notifications — _planned_

In-app first, through Laravel's notification system so email, WhatsApp, SMS and
Slack can be added later as channels without touching the business logic.

### Scheduled work — _planned_

Daily low-stock scan, expiry alerts, overdue material requests, reconciliation,
inventory snapshots and scheduled reports.

---

## Interface

Desktop first, then laptop, tablet and mobile. Factory and warehouse staff need
to record receipts, transfers and consumption from a tablet on the floor, so
those actions work at every width.

Every list shares one component: search, sort, filter, paginate, choose columns.
Every figure a user is not entitled to see is absent from the payload, not
merely hidden.
