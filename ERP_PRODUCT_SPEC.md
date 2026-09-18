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
with a batch per line, and administrators close it once the facility is live. The administrator books the old stock of a whole facility from
**Store → Old Stock Entry**: raw materials, packaging and finished goods in
three sections, by hand or from a filled-in sheet, one posting per store,
every batch QC passed automatically and usable at once.

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

### Approval engine — _built_

Approval by risk, not just amount. A recipe version is always signed off
by someone other than its author. A manufacturing release goes for a
second signature when a trigger fires: a non-standard recipe version, a
client job with a negative margin or no terms, a product below its yield
target, abnormal wastage or an unexpected price increase on a material it
uses, or the requester being its author. Releasing a lot QC rejected is an
override with a second signature. The checker is the requester's approving
authority (set on the employee) or anyone holding the workflow's
permission, never the requester; the person who booked a delivery in
cannot release it from QC. Every decision is a signed, never-edited
record. Workflows are declared in `config/approvals.php`.

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

A delivery is booked in from the supplier's bill (uploaded from the receipt
screen or straight from the Raw Materials and Packaging Materials pages) and
posted. Each bill line is matched to the material on file by code, HSN, name
or INCI name; a line that matches nothing can be added as a new material on
the spot. The destination must be the kind of store the material lives in.
Every line becomes a numbered batch (`RM250909-001`). Material that needs QC
lands in the quarantine store of the facility it arrived at and opens an
inspection; material that does not goes straight to its store. A facility
without a quarantine store cannot receive material that needs QC, and the
receipt says so. QC approves (the whole batch moves to the store), rejects (it stays
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
delivery challan, the ERP reads the vendor, invoice number and date, totals,
and every line with its quantity, unit, rate, batch number and dates. A PDF
printed from the supplier's billing software is read on the server by the
built-in reader, with no key and no network; a photo or a scan is read by
Claude when a key is set. The ERP then matches the vendor by GSTIN or name
and each line to the item master by code, HSN, name and INCI name, and the
form opens filled in. The person
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

### Factory intelligence — _built_

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

**Factory command centre.** One screen for management: what is running,
what is delayed, what is waiting for QC, what is short, what is dispatching
today, what requires approval, and what could stop production tomorrow.
Refreshes every minute.

**Exception-based management.** Management is not asked to inspect
everything. Twelve rules read the floor and report only what crossed a
threshold: abnormal wastage, delayed batch, high material variance,
unexpected price increase, slow QC, stock discrepancy, unusually high
rejection, production below target, plan not started, material request
unfilled, transfer late, could stop production.

**Automatic escalation.** An exception that stands too long is raised to
the roles responsible, then to their seniors, once per level, and closed
when it clears. Notifications land under the bell.

**Real-time batch progress.** The floor records exact stages and how far
through each is; management sees the bar on the dashboard, the command
centre and the order.

**Material consumption intelligence.** Standard vs issued vs consumed vs
returned vs wastage per batch, and the trend per material across batches.

**Yield analytics and the cost variance engine.** Planned against actual
yield per product with the leakage in units; standard against actual cost
per batch, explained by price change, extra consumption, wastage, charges
and yield.

**Client profitability.** Revenue, costs and margin per client, product
and batch for contract manufacturing.

**What-if simulation and capacity planning.** A batch that does not exist
yet, priced, scheduled and its impact on the plan shown; plant capacity
against bookings week by week.

**Immutable critical records.** Ledger postings, approval actions, stage
events and batch adjustments are never edited; a mistake is corrected by a
reversal that stays in the ledger beside the original.

**Recall management.** From any lot, forwards through every batch it went
into to the finished goods, their stores, transfers, dispatches and
clients; backwards to the lots a batch was made from and their suppliers.

**Controlled documents.** SOPs, specifications, artworks, COAs and QC
standards with version history and approval status; production references
the approved current version.

**QR and barcode on the floor.** Batch stickers, rack labels and batch
cards carry QR codes; a phone camera opens the thing scanned in the floor
mode. **Scan before issue:** at the kettle every drum is scanned against the
batch and passes only when it is the right material, released by QC, in
date, the owner's own stock and the batch the store reserved; anything else
is blocked with the reason and the attempt is recorded. The factory can
require every raw material to pass before a batch starts.

**Stock counts and inventory accuracy.** A count freezes the system
quantity per batch, the counter scans and enters what is on the shelf, and a
different person approves it, at which point every difference is posted
through the ledger. Inventory accuracy is the share of lines where shelf
matched system.

**Mobile floor mode.** A one-column, large-target screen for a phone at
`/floor`, installable to the home screen: scan, issue, record the stage,
count, photograph. No data is cached offline.

**Department scorecards and OTIF.** Planning, purchase, stores, QC,
manufacturing, packaging and dispatch each carry a scorecard read from the
documents they keep: plan adherence, lead-time adherence, booking time,
inventory accuracy from stock counts, QC turnaround and rejection, yield
and delays, packaging rejection, and dispatch OTIF (client jobs completed
on time and in full). Each department has a score; the factory score is
their average.

**Process performance, not surveillance.** For each step of the flow, how
many went through, the average and longest time, what is waiting now and
how long the oldest has waited. Volumes and times belong to the process
and the department; the ERP does not rank people.

**The ERP assistant.** A question in plain words is answered by Claude
from the ERP's own data, through read-only tools that run under the
asking person's own permissions: stock outlook, reorder advice, exception
feed, command centre, order status, batch trace, scorecards, stock risks
and client profitability. It never changes anything, and says which
figures it read.

### Costing — _planned_

`ProductCostingService`: raw material, packaging, labour, overhead, wastage and
configurable costs, producing batch cost, cost per kilogram and cost per unit.
The consumption each manufacturing order already records is its input.

Historical costing is preserved: a material price rise never rewrites what an
earlier batch cost.

### Dispatch — _built_

Finished goods leave a facility against a tax invoice, and only from the
finished goods store. A consignment is written up batch by batch from
QC-released stock, priced as the invoice carries it; the tax follows the
seller's and buyer's states. A contract client's batches go only to that
client — the customer linked to them — and the company's own to anyone.
Nothing moves at write-up.

E-invoicing is enforced, not advised: for a GST-registered buyer the goods
do not leave until the invoice number and date, the IRN and
acknowledgement the Invoice Registration Portal returned, and the signed
invoice with its QR are on record. The ERP prepares the e-invoice as JSON
in the IRP's schema (INV-01 v1.1) for the bulk tool or a GSP; the IRN comes
back and is recorded. An unregistered buyer is invoiced without an IRN and
the plain invoice is uploaded instead. When the goods go, the stock leaves
through the ledger against the consignment, the transport is recorded and
a delivery challan prints; once gone, a consignment is delivered, never
cancelled. Every paper that travelled is kept with it, and management sees
everything that ever left, to whom, on which invoice, on which vehicle.

Each facility bills under its own company: the factory's invoices name the
manufacturing company, the depot's the brand owner.

### Accounting and sales — _planned_

Sales orders and allocation from finished stock ahead of dispatch; marketplace
imports for Amazon and Flipkart, stock transfers to fulfilment centres, and
reconciliation. Shown in the navigation now so the shape of the system is
visible.

### Reporting, imports and exports — _partly built_

The formulation import and the opening-stock sheet import are built. Excel
import for masters, vendor prices and marketplace sales, validated before
anything is committed, and exports for the stock ledger, inventory,
purchases, production, sales and costing, are next. Large files will run on
the queue.

### Notifications — _built, in-app_

Through Laravel's notification system on the database channel: a bell with
the unread count, a notifications page, read on opening. Email, WhatsApp,
SMS and Slack can be added as channels without touching the business logic
that raises them.

### Scheduled work — _first job built_

The escalation pass runs hourly. Daily low-stock and expiry digests,
reconciliation, inventory snapshots and scheduled reports are next; the
scheduler entry is already in place.

### Administration: data — _built_

A factory learns the ERP by using it, and what it learns on leaves a trail:
trial deliveries, half-planned batches, stock that was never on a shelf.
**Administration → Data**, the system administrator's alone, clears exactly
the kinds of data chosen — plans and batches, deliveries and QC, stock and
movements, approvals and documents, formulations, materials and products,
vendors and clients, facilities and stores — and shows what each choice
drags in with it before anything happens, because the dependencies are the
foreign keys and not a preference. It is one transaction: all of it or none.

People, roles and permissions, the reference data the ERP is built on, and
the audit trail are never touched, whatever is ticked; the clearing itself is
written to the audit trail with who did it. Confirmation is the company name
typed in full. The same thing runs headless as `erp:reset`.

The other half of the screen fills an empty ERP with **one worked example**
end to end — a facility with its stores, vendors, a contract client,
materials, a product with its bill of materials, an activated formula,
opening stock, a delivery through QC, a third-party plan and a completed
batch — so the whole flow can be walked before real data goes in. It creates
no login accounts: the people on the system are the real ones.

---

## Interface

Desktop first, then laptop, tablet and mobile. Factory and warehouse staff need
to record receipts, transfers and consumption from a tablet on the floor, so
those actions work at every width.

Every list shares one component: search, sort, filter, paginate, choose columns.
Every figure a user is not entitled to see is absent from the payload, not
merely hidden.

The left menu carries destinations, not tables. Anything that has a natural
home elsewhere is reached from there instead of taking a line of its own: a
material's batches are on the material's page, and a delivery is received
from the store it arrives into, with that store already filled in. Screens
dropped from the menu keep working and keep their addresses — the clutter
goes, the capability does not.
