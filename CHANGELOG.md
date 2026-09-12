# Changelog

Notable changes to HRBD ERP. Newest first.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project is pre-release; versions begin at 0.1.0 and the schema may change
between them.

---

## [Unreleased]

### Added — Facilities, stores and employee assignments (Phase H)

- **Facilities above stores.** Company → Facility → Store → Location → Stock.
  Every existing warehouse row is now a store inside a facility; the
  migration attaches what is already on file to one manufacturing facility
  (Rudrapur when the data names it) in place — no store id, code or ledger
  line changes, and no data is reset.
- **Facility types** (Manufacturing, Warehouse / Storage, Distribution Centre,
  Office, Third Party Warehouse, Marketplace Warehouse, Depot, Other) and
  **store categories** (RM, PM, FG, Quarantine, Rejected, Production Staging,
  Packaging Staging, Samples, Returns, Damaged Goods, Marketplace, General) are
  editable masters under Settings. A category in use can be renamed, re-badged
  or deactivated, never deleted, and what it holds is fixed once stores use it.
- **Capabilities** on each facility — Storage, Receiving, QC, Manufacturing,
  Packaging, Dispatch, Returns. A production plan or manufacturing order can
  only be raised for a facility with Manufacturing on; nothing checks a
  facility's name.
- **Facilities & Warehouses** screen: Code · Facility · Type · City · Stores
  (RM · PM · FG · QUAR badges) · Employees · Stock Value · Status, with search
  and filters by type, city, capability and status; a five-step onboarding
  wizard (details, capabilities, store checklist, employees, inventory setup);
  a facility page with Overview, Stores, Inventory, Employees, Stock
  Transfers, Incoming, Dispatch, Activity and Settings tabs (Production only
  where manufacturing is on); and a per-store screen with the store's stock,
  batches, movements and actions.
- **Stores can be added to a live facility** from its page without touching
  anything already recorded. A store with ledger history cannot be deleted
  ("This store cannot be deleted because operational history exists.") —
  it is deactivated, and only once it holds nothing.
- **Employee assignments**: a person is assigned to a facility, or to one
  store within it, with a primary assignment and any number of others; no
  duplicate user records. Stock actions need the role permission **and** an
  assignment covering the facility or store; Super Admin, Owner, Director and
  Management work company-wide. Managed from the facility's Employees tab and
  from the employee's own record.
- **Opening stock** per facility: item, batch, quantity in any convertible
  unit, manufacturing and expiry dates, rate and remarks, posted as immutable
  `OPENING_BALANCE` ledger transactions with a lot per line. Administrators
  switch opening stock entry off once a facility is live.
- **Inter-facility stock transfers**: Draft → Requested → Approved (stock
  held at the source, one line per batch) → Packed → Dispatched (stock moves
  to the system's in-transit position) → In Transit → Received, Partially
  Received or Received with discrepancy (lost or damaged quantities written
  off from transit); Rejected and Cancelled release the hold. Nothing shows at
  the destination before receipt, the batch is the same batch at both ends,
  and an optional inspection routes received stock into the destination's
  quarantine with a QC inspection. Finished goods skip re-QC by default.
- **Requirement checks count the planning facility only.** With 25 kg at
  Rudrapur and 100 kg at Delhi, a plan for 50 kg shows 25 available and 25
  short, and the line offers "Available at Delhi: 100 → Create Stock Transfer
  Request" with the transfer pre-filled.
- **Store-specific thresholds** (`store_item_levels`) override an item's
  reorder and critical levels for one store.
- **Facility filter** on the dashboard (All / one facility; production
  widgets hidden for a facility that does not manufacture), on the Stock
  screen (facility → store), and on the Stores list.
- Permissions: `facility.view/create/edit/deactivate/export`,
  `warehouse.deactivate`, `user.assign_facility`, `user.assign_store`,
  `inventory.opening_stock`, `inventory.approve_transfer`,
  `inventory.receive_transfer`. Roles pick them up through
  `erp:sync-permissions` on deploy.
- Demo data now seeds the Rudrapur plant (RM, PM, FG, Quarantine, Rejected,
  Production Staging, Samples, Marketplace) and a Delhi warehouse with only a
  finished goods store, plus everyone's assignments.

### Fixed

- **Raw material stock control (issue #1).** The reorder level, minimum stock
  and maximum stock fields now show the material's stock unit beside the
  figure (`100 KG`), explain which alert each drives, and — for raw and
  packaging materials — are required: a material cannot be saved without its
  reorder level and minimum stock, and the minimum (Critically low) cannot sit
  above the reorder level (Low). Finished goods may still leave them blank.

### Changed — Permissions, seeding and documentation (Phase G)

- **Deploys no longer reset roles.** `db:seed` and `erp:sync-permissions`
  now hand each built-in role only the abilities that are new to the
  catalogue and belong in its defaults; whatever an administrator granted or
  withdrew on the Roles screen stays. `erp:sync-permissions --roles` remains
  the deliberate reset.
- **Demo data for a working day** outside production: a delivery passed by
  QC, an invented recipe with a pack list, a plan short of materials with its
  two requests, and a batch on the floor — so a fresh development database
  shows every screen with something on it.
- Documentation brought up to date with the factory flow: product spec,
  database schema, security architecture (the formula gates as built), roles
  (generated from the code), roadmap, deployment, backup and the first-deploy
  guide's set-up steps.

### Added — Workflow navigation and the dashboard (Phase F)

- **Navigation in the order work flows**: Store (Raw Material, Packaging and
  Finished Goods stores, Goods Receipts, Batches) → Quality Control →
  Planning & Purchase → Manufacturing → Packaging → Accounting → Dispatch →
  Human Resource → Master Data → Administration. Sections still on the
  roadmap (Costing, Dispatch) are shown disabled so the shape of the system
  is visible.
- **Dashboard** in the card style of the supplied design: a greeting card
  with the day's headlines and quick actions; key figures (in production,
  plans awaiting production, open material requests, awaiting QC, critically
  low materials, expiring batches); each store as a donut of its items by
  alert level (Healthy / Moderate / Low / Critical / Out of stock); what is
  in production with its stage; receiving and QC decisions per day over a
  selectable 7 / 30 / 90 days; units packed per week; materials to watch;
  expiring batches; what is coming up in the next fortnight; recent
  activity. Every card is gated by the permission of the module behind it,
  and each person can hide the cards they do not need (remembered in the
  browser).
- **Theme**: warm orange accent on a light blue canvas, in light and dark.

### Added — Manufacturing (Phase E)

- **Manufacturing orders** opened from a checked plan, taking its material
  list. _Approve_ holds every material in its store, drawing on the
  earliest-expiring QC-approved batches — all or nothing, and if anything is
  short the message names each shortfall. _Start_ issues the held raw
  materials to the kettle through the ledger. _Complete_ records the bulk
  output, units packed and yield, uses up the held packaging, releases
  anything left, and posts the finished batch as a new lot (`FG250909-001`)
  with an expiry from the product's shelf life — into quarantine with a QC
  inspection when the product needs QC, otherwise straight to the finished
  goods store. _Cancel_ releases what is held; what the kettle already took
  stays taken.
- The plan follows its order: in production on approval, completed on
  completion, back to where it was on cancellation.
- 11 tests: material list from the plan, all-or-nothing approval, FEFO
  holds across batches, ledger consumption, QC and no-QC outputs, units
  required for piece-stocked products, cancellation before and after start,
  lifecycle order, and the screens by role.

### Added — Planning & Purchase (Phase D)

- **Production plans.** Pick a formula with an active recipe and a batch
  size; the plan is checked against the stores the moment it is saved. Every
  raw material is scaled to the batch, expressed in its stock unit and set
  against what the raw material store can release for production
  (QC-approved, unexpired, unreserved): required, in store, short, and the
  store's alert level now and after the run (Moderate / Low / Critically low
  / Out of stock). Packaging is planned the same way from the product's pack
  list and net content, in whole pieces. A plan can be re-checked at any time.
- **Production Material Requests (PMR).** One request per store (raw
  material, packaging), numbered `PMR-yymm-00001`, listing what the store
  must provide, the shortfall to order, and the quantity that also brings
  the store back to its reorder level. Printable as an A4 PDF for the store
  and purchase. Requests close themselves as deliveries are booked in
  against them, line by line.
- **Goods receipts against a PMR.** The receiving screen offers open
  requests; choosing one fills in the store and the quantities still to come.
- **Packaging per unit** on each product: bottle, cap, label, a share of a
  carton — edited on the product screen.
- New `planning` module (view, create, edit, approve, cancel, export).
  Factory Managers plan freely; Production Managers plan and raise requests;
  purchase and the stores see the requests; Directors approve and cancel.
- 13 tests: requirement arithmetic including alert-level movement and
  restock quantities, QC-released stock only, unit counting through density,
  whole-piece packaging, PMR generation per store, delivery closing a PMR,
  cancellation cascade, and the screens by role.

### Added — Formulations (Phase C)

- **Formulas with versions.** A formula is the identity; each version is one
  recipe of percentages against a reference batch (100 g, 100 ml …). Only a
  draft can be edited; activating it supersedes the previous active version,
  and a partial unique index guarantees a formula never has two active
  recipes. Production will reference the version it was made from.
- **Second factor in front of every recipe.** The list shows names only.
  Opening, editing, importing or scaling a recipe requires a formula PIN
  (4–8 digits, stored hashed, set with the account password), which grants an
  unlock for a configurable 5–60 minutes bound to the browser session. Five
  wrong PINs lock the module for 15 minutes. Every attempt, view, scale, edit
  and import is written to an append-only `formula_access_logs` trail;
  ingredient rows are deliberately kept out of the general audit log, and
  recipe responses are sent `Cache-Control: no-store`.
- **Scaling.** Any version can be worked out for a real batch in any mass or
  volume unit, with each material also expressed in the unit the store holds
  it in. A mass↔volume conversion for a material with no density is estimated
  at 1 g/ml and flagged.
- **Excel import** (`Formulations → Import`, or `erp:import-formulations`).
  Reads the two layouts chemists actually use — a column table of INCI, trade
  name and %, or one cell per line read top to bottom with wrapped names,
  grades, "QS to 100 ml" and functions — and shows a full plan before writing:
  which formulas are new, which become a new version, which are identical and
  skipped, which raw materials will be added to the master data. Ambiguities
  (re-joined wrapped text, a dropped supplier name, a missing amount, a sheet
  with no filler) are reported as warnings, not decided silently.
- **INCI name** on raw materials, searchable and shown on the item screens.
- New abilities `formula.delete` and `formula.import`; Factory Managers may
  now create, edit and import formulas. Approval stays with Directors and above.
- 42 tests: parser layouts, versioning rules, database-level single-active
  guarantee, PIN lockout and expiry, session binding, audit-log hygiene,
  import planning and idempotence, console and screen flows.

### Added — Receiving and quality control (Phase B)

- **Goods receipts.** Deliveries are booked in from the delivery note and
  posted to the ledger. Every posted line becomes a numbered batch
  (`RM250909-001` …); material that needs QC lands in the quarantine
  warehouse and opens an inspection, material that does not goes straight to
  its store.
- **QC checkpoint.** Approve, reject or hold. Approval moves the whole batch
  from quarantine to the store through the ledger; rejection leaves it locked
  in quarantine; a hold can still be approved later.
- **Batch sticker.** Every approved batch gets a 100 × 70 mm PDF — QC
  APPROVED, batch number, received / manufactured / expiry dates, quantity,
  supplier reference — ready for a label printer.
- **Store screens.** Stock per store with Moderate / Low / Critically low /
  Out-of-stock levels and an expiring-soon count; goods receipts; the QC
  queue; batches with their movement history.

### Added — Inventory ledger (Phase A)

- **Immutable ledger.** Every movement is an `inventory_transactions` row with
  lines; balances are derived, never edited. Receipts, issues, transfers and
  adjustments all go through one service that locks the balance rows it
  touches, so two concurrent postings cannot oversell — proven by a test that
  forks real processes against PostgreSQL.
- **Lots, reservations, alert levels.** Batches carry QC status and expiry;
  reservations hold stock for production and are released or consumed under
  the same locks; each item's minimum stock and reorder level define its
  Critical / Low / Moderate bands.
- **Document numbers** that never repeat or skip, per document type and month.

### Fixed

- Validation rules of the form `Rule::exists(...)->where('flag', false)`
  flattened `false` to an empty string, which PostgreSQL rejects as a boolean.
  Such rules now use the closure form.
- Services typed their date parameters as the mutable `Carbon` while models
  return `CarbonImmutable`; they now accept any `CarbonInterface`.

### Added — ERP interface and master data

- **Navigation and dashboard** built from the permission catalogue. Entries a
  user cannot reach are not rendered, and empty groups are dropped. Dashboard
  tiles are filtered the same way, so no figure appears that its viewer is not
  entitled to.
- **One `DataTable` for every list**: server-side search, sorting, filtering,
  paging, and per-viewer column choices. The state is echoed back from the
  server, so the controls always show the query that actually ran.
- **Warehouses** — create, read, update, soft delete, with locations listed.
- **Raw materials, packaging materials and products** — full CRUD, sharing one
  form and one table component while showing the fields each type actually has.
- **Vendors** — full CRUD with GSTIN uniqueness and an approval flag.
- **Users** — full CRUD, activate and deactivate, role assignment restricted to
  Super Admin.
- **Roles** — a permission editor, audited with the complete before and after.
- **Audit log** — a filterable viewer and a per-entry change view.
- 42 further tests, covering the screens end to end.

### Fixed

- Eloquent inferred a relation's foreign key from the calling class, so the item
  subclasses sharing one table looked for `raw_material_id` and
  `packaging_material_id`. Keys are now explicit.
- One shared `ItemPolicy` could not tell which module it was authorising for a
  class-level ability, letting a Designer open the raw material list. Each item
  type now has its own policy.
- Route model binding to the shared `Item` let `/products/{id}` resolve a raw
  material. Bindings now resolve the concrete subclass, so a mismatched id is a 404.

### Changed

- **Super Admin no longer bypasses a check about its own account.** The rules
  forbidding self-deletion, self-deactivation and self-role-assignment exist for
  exactly the account that holds this role; a blanket bypass waived them for the
  only user they were written for.
- **Procurement now owns the material masters.** The role definitions left
  nobody below Owner able to create a raw material.

---

## [0.1.0] — Foundation

### Added

- **Laravel 13** application with Inertia 3, React 19, TypeScript and
  Tailwind 4, on PHP 8.4.
- **PostgreSQL** as the primary database, for the application and the test
  suite. Tests run against PostgreSQL rather than SQLite because the ERP depends
  on `SELECT … FOR UPDATE` row locking and `NUMERIC` precision that SQLite does
  not reproduce.
- **Authentication** through Fortify: sign-in, password reset, sessions,
  throttling, with two-factor and passkeys wired and SSO structurally possible.
- **Employee accounts**: employee code, department, designation, status, last
  sign-in, soft deletes. Deactivation ends any session already open, rather than
  only blocking the next sign-in.
- **Access control**: 97 permissions in a single catalogue, 16 roles defined
  against it with wildcard expansion, enforced by policies on the server.
- **Audit trail**: append-only, recording who, what, before, after, IP, session
  and route. Immutability enforced by the model, by the absence of any write
  route, and — in production — by revoking write access from the application
  database role.
- **Units of measure** with one base unit per dimension and a single
  `UnitConversionService` using `brick/math`. Quantities are accepted as
  strings, integers or `BigDecimal`, never floats.
- **Master data schema**: one `items` table for raw materials, packaging and
  finished goods; warehouses and locations; vendors; item categories.
- **Approval engine schema**: one set of tables for every module's approvals.
- **Seeders** for units, departments, roles and permissions, plus demo data
  outside production.
- 98 tests covering conversion arithmetic and its refusals, role enforcement,
  audit immutability and account status.

### Changed

- **Self-service account deletion removed.** It contradicted the ERP's own rule
  that nobody may delete their own account, and would have orphaned the audit
  and ledger references naming that employee.
- **Remote font CDN replaced with a system font stack.** An internal ERP should
  not make third-party requests to render its own interface.

### Notes

- Pack units — carton, box, drum — are flagged as needing a per-item factor and
  refuse to convert without one, rather than silently treating a carton as a
  single piece.
- Larastan is configured but not installed in this environment; see
  [DEVELOPMENT_ROADMAP.md](DEVELOPMENT_ROADMAP.md#carried-debt).
