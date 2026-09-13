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

### Facilities and stores — _built_

Company → **Facility** → **Store** → Location → Stock. A facility is a site —
the Rudrapur plant, a Delhi warehouse, a marketplace fulfilment centre — with
an editable **type** and a set of **capabilities** (Storage, Receiving, QC,
Manufacturing, Packaging, Dispatch, Returns). Workflows check the switches,
never the name: a manufacturing order can only be raised for a facility with
Manufacturing on.

Each facility has stores, one per **store category** (RM, PM, FG, Quarantine,
Rejected, Production Staging, Packaging Staging, Samples, Returns, Damaged
Goods, Marketplace, General — an editable master). Stores can be added to a
live facility at any time; a store with history is deactivated, never
deleted. Stock is always attributable to facility → store → location.

People are **assigned** to a facility, or to one store within it. A stock
action needs the role permission and an assignment covering the place;
Super Admin, Owner, Director and Management work company-wide.

A five-step wizard sets a facility up: details, capabilities, the store
checklist, employees (optional), and whether to book opening stock now.
**Opening stock** is booked through the ledger as `OPENING_BALANCE` postings
with a batch per line, and administrators close it once the facility is live.

**Stock transfers** move stock between facilities: requested, approved (held
at the source, one line per batch), dispatched into the in-transit position,
received — in full, in part, or with a discrepancy written off. Nothing shows
at the destination before receipt; an optional inspection routes it through
the destination's quarantine.

At the destination the consignment is booked in only after the paperwork that
came with it is matched to the transfer. Dispatch prints a challan with a QR
and an eight-character inward code; the receiving store scans the QR (a
handheld scanner, or the phone camera, which opens the transfer with the code
on the URL) or types the code, or uploads the transporter's invoice or LR for
Claude to read the transfer number off. Until that scan nothing can be
received and nothing shows at the destination; after it, what arrived is
booked in and the stock appears in the destination store (or its quarantine
when an inspection was asked for).

### Master data — _built_

**Stores** (the `warehouses` table) with locations, belonging to a facility
and a store category; quarantine and in-transit stores hold stock that is on
the books but not available to issue.

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
low**, **Out of stock** — which a store may override for itself, and a count
of batches expiring soon. The dashboard and the stock screen filter by
facility. Every
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

A plan belongs to a manufacturing facility, and availability is counted at
that facility's stores alone. Stock held elsewhere is reported per facility
with a one-click transfer request rather than counted as available.

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
> | Material       | Required | In store | Short    | Level          |
> | -------------- | -------- | -------- | -------- | -------------- |
> | Purified Water | 18.85 kg | 0 kg     | 18.85 kg | Out → Out      |
> | Glycerin       | 0.5 kg   | 2.1 kg   | —        | Low → Critical |
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

Goods receipts, vendors and material requests are built. A goods receipt is
booked off the supplier's bill: the storekeeper uploads the invoice or
delivery challan as a PDF or a photo, Claude reads the vendor, invoice number
and date, totals, and every line with its quantity, unit, rate, batch number
and dates, the ERP matches the vendor by GSTIN or name and each line to the
item master by code, HSN and name, and the form opens filled in. The person
confirms the item and unit where the reader could not, adds a vendor the ERP
does not know from a pop-up on the same screen, and posts. Only the plant
head or an administrator (`purchase.receive_manual`) may key a receipt in by
hand or change what the reader found; everyone else's receipt must come off a
bill, which stays attached to it. Purchase orders with approval, and supplier
price history with its dashboards (last, average, previous, change, cheapest
supplier, trend), are next.

### Third-party / contract manufacturing — _built_

The company also manufactures for other brands, and does so through the same
workflow: Planning & Purchase → Raw Material → QC → Manufacturing → Bulk QC →
Packaging → Finished Goods. A batch is either _Own brand_ or _Third party_;
nothing is duplicated.

A **contract client master** holds each client (`TP-001`), their addresses,
GSTIN, terms and agreement. Products record whom they are made for; formulas
record whose they are (company, client owned, joint), and a client's formula
can only be planned for that client. A third-party **plan** names the client,
their PO, product name, delivery date and the material source — ours, the
client's, or mixed with the client-supplied materials ticked. **Client-supplied
material** is booked in on a goods receipt in the client's name and stays the
client's: an own-brand batch never uses it, another client's job never uses
it, and a client-supplied line counts only that client's own batches, showing
_Awaiting client material_ rather than a purchase requirement when short.
Manufacturing orders carry the client through approval (client lines held from
the client's batches), the kettle and completion; the finished batch is posted
as the client's with a batch number that says so. Each job records its
**commercial terms** and shows its costing: our material at actual batch
cost, the client's material valued but not charged, the manufacturing charge,
other charges, GST, total and margin. The client's material is **reconciled**
from the ledger (supplied, consumed, wastage, balance). Artwork approvals and
client QC specifications are kept per client and product, and the dashboard
carries a third-party section. Dispatch and accounting will pick the client up
from the finished batch when they are built.

### Dashboard — _built_

A greeting with the date, a live clock in the factory's time zone and the
moment the person signed in, then the day's headlines; in production, plans awaiting production,
open material requests, awaiting QC, critically low materials, expiring
batches, materials to order before they run out; each store's items by alert level; what is on the floor and at which
stage; receiving and QC per day over 7 / 30 / 90 days; units packed per week;
materials to watch; what is coming up. Every card is gated by the permission of
the module behind it, and each person can hide the ones they do not need.

### Factory intelligence — _first layer built_

Every screen should answer three questions: what is happening now, what is
likely to go wrong next, and what should we do. The first layer answers them
for stock.

**Predictive reordering.** Rather than waiting for a material to fall Low or
Critical, the outlook for each material is read from the ledger and the
planning documents: usable stock (released, not quarantined, not expired,
not reserved), what approved and running batches still need beyond their
reservations, what checked plans will take, what purchase has already
requested, the average daily use over the last 90 days, the supplier lead
time (the material's, else measured from the supplier's deliveries, else a
default), the days of cover and the run-out date. From these the shortfall,
the recommended order quantity (minimum order quantity, pack multiple and
maximum stock respected), the date to order by, and a status: Order today,
Order this week, Watch, Covered. The **Reorder Advice** screen lists the
urgent first; the **outlook panel** on every material's page says the same
in sentences.

**Smart purchase recommendation.** The supplier with the best recent price
per stock unit, their last price, measured lead time, delivery count and
last delivery, and what is pending.

**Slow-moving stock.** Batches idle 30 / 60 / 90 / 180+ days since their
last issue, valued at landed cost, so management sees blocked working
capital.

**Expiry risk.** Whether each batch expiring in the window will be used
before it expires, first-expiry-first-out at the material's rate; the
quantity and value at risk.

**Still to come in this layer:** the command centre and exception feed,
batch stages, consumption and yield analytics, cost variance, client
profitability, what-if simulation, capacity, risk-based approvals, recall
tracing, shop-floor scanning and the assistant.

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
