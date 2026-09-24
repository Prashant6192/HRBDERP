# Changelog

Notable changes to HRBD ERP. Newest first.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project is pre-release; versions begin at 0.1.0 and the schema may change
between them.

---

## [Unreleased]

### Added — Online orders: marketplace labels, packed by scan

The Delhi depot's marketplace parcels, from the agency's upload to the
courier's signature, so a printed label can no longer be forgotten.

- **Dispatch → Online orders.** The e-commerce agency uploads the day's
  label PDFs for a brand, exactly as Meesho, Flipkart, Amazon or Myntra gave
  them. Every label becomes a parcel: its AWB, courier, COD or prepaid,
  amount, order and invoice number, customer's state, and the SKU and
  quantity it carries. **Meesho and Flipkart labels are read exactly from
  their text** on the server, at no cost; **Amazon and Myntra send
  pictures**, so their pages go to the AI reader (the same one that reads
  supplier bills), which also keeps an Amazon label together with the
  invoice pages after it. A page nobody could read still becomes a parcel,
  flagged, to be typed in — it is never silently dropped.
- **The same file twice is refused; a label already uploaded is skipped**
  with a warning naming it, so a re-download from the portal cannot double
  an order. Labels registered to ship from another state than the store
  they leave (Uttarakhand labels leaving Delhi) are flagged.
- **SKU mapping, once.** The SKU text a marketplace prints ("Medicated oil
  300 ml", "Medicated_Oil_500ml_Po2") is mapped to the product once per
  brand and marketplace — with units per order for a pack of two — and every
  label after is matched without asking, however its spacing and case come
  out.
- **Stock is held from the upload** in the brand's store — Paper Market's
  finished goods store by default — oldest batch first, first uploaded
  first served. What the store cannot cover is marked short, with the
  shortfall product by product, and held as soon as stock arrives
  (**Check stock again**, or on its own at print and at packing). A brand
  selling a contract client's goods takes only that client's batches.
- **"That's all for today"** tells the depot, by bell and by email, that
  the labels are ready to print.
- **Print by courier.** All, only what is new, one courier's pile, or one
  label: the browser puts the pages together from the marketplace's own
  files — never altered — in courier order. Every print is logged; a reprint
  is counted.
- **Floor → Pack parcels.** Put the goods in the box and scan the label.
  The phone says **PACKED** in green with the product and count in large
  type, or **STOP** in red with the reason: the order was cancelled, it was
  already packed (by whom, when), the product is not mapped, there is not
  enough stock, or it belongs to another facility. A beep for yes, a buzz
  for no. **The stock leaves the store at that moment**, through the ledger
  (`MARKETPLACE_SALE`), batch by batch against the parcel — so online sales
  now feed reorder advice and recall tracing.
- **Floor → Courier pickup.** Tick or scan what each courier takes and print
  the numbered handover sheet (`HO-yymm-00001`) for their signature.
- **Nothing printed is forgotten.** Labels not packed by the cut-off (4 PM
  by default) are raised as an exception per facility per day, standing
  until every parcel is packed or cancelled with a reason, escalating from
  the Dispatch Manager to the Owner and Director after two hours. Parcels
  that cannot be packed (short, not mapped, no AWB) are raised too. The
  command centre shows today's online orders.
- **Cancelling** before packing lets go of what was held; after packing,
  and before the courier has it, the posting is reversed and the goods go
  back on the shelf. A parcel with the courier comes back as a return
  (next release). Packing without a scan is the office's call and records
  the reason.
- **Dispatch → Brands** sets where each brand ships from, whose stock it
  sells, and which agency accounts upload for it. Rahat Rooh and Cleanse
  Ayurveda are set up, with Meesho, Flipkart, Amazon and Myntra.
- **E-commerce Agency** role, for the outside agency: uploads and follows
  its own brands' parcels, and sees nothing else in the ERP — no stock
  figures, no products, no prices, no other menu. The **Accounts Manager**
  prints; the **Store Executive** packs and hands over; the **Dispatch
  Manager** has all of it.
- `ERP_ONLINE_ORDERS_CUTOFF` (default `16:00`, local time).

### Added — Forgot password, handled by the employee themselves

- **Forgot password?** on the sign-in card now emails a link that works once,
  for 60 minutes, to choose a new password. No administrator is involved. The
  email is the ERP's own: its name, the amber button, when and from which
  browser and IP address the request came, and that ignoring it changes
  nothing.
- **The form reveals nothing.** Every request gets the same reply, whether the
  address is unknown, deactivated or real, so it cannot be used to find out
  who has an account. Three requests per address and ten per network every
  fifteen minutes, on top of one per account per minute.
- **A deactivated account gets no email,** and a link issued before the
  account was withdrawn is refused.
- **Every other session is signed out** when a password changes, whether reset
  from a link or changed in settings; the session making the change carries
  on. "Keep me signed in" cookies from before stop working. The reset is
  written to the audit trail and clears a forced password change.
- **Real addresses behind the load balancer.** The application now trusts the
  hosting platform's proxy headers, so the reset link comes out as `https://`
  and the IP address shown in the email and recorded in the audit trail — for
  sign-ins too — is the person's, not the balancer's.
- **Needs mail configured** in the environment: SMTP settings from Postmark,
  Resend or Google Workspace, and a from-address on a verified domain. See
  DEPLOYMENT.md. Without them the email goes to the log and nobody receives it.

### Changed — The address opens the sign-in card

- The bare address, `erp.rahatrooh.com`, now goes straight to the sign-in card,
  or to the dashboard for someone already signed in. The framework's welcome
  page is gone; this ERP has nothing to show anyone who cannot sign in.

### Added — The management view on a phone

- **`/m`**: the factory on a phone for anyone holding `report.view`. One
  home screen with one number per question — batches in manufacturing,
  batches ready, raw material in stock and what it is worth, what to order,
  what is still to be billed, finished goods value, formulas live, 3P
  clients — and a screen behind each: batches on the floor with stage,
  awaiting QC and recently completed with their yields; stock by material
  at batch cost with what is in quarantine; purchase advice most urgent
  first; every formula, its version and whose it is; every client with jobs
  open, running and made; every finished batch on the shelf with the
  artwork it was packed to; completed third-party jobs not yet dispatched
  at the agreed terms, and consignments written up but not invoiced. A tab
  bar under the thumb, nothing entered here, the full ERP one tap away. It
  is reached from the **Management** link in the header, and installs as
  an app on a phone the way the floor mode does.

### Added — Batch reconciliation: what was planned, what came out, what was kept

- **Complete batch** now takes the whole account instead of one output
  figure: bulk made against bulk planned, **units filled, rejected at
  packing and kept as samples**, bulk left unpacked and where the loss went.
  Good units — filled less rejected less samples — are the units that reach
  stock; rejects and samples are on the record and never do. Three yields
  are kept because they answer three questions: **bulk yield** (kettle
  against plan), **packing yield** (kept against filled) and **overall
  yield** (good units against the units the batch was planned for). The
  batch page shows the reconciliation line by line; the management view and
  the batch card carry the overall yield and the rejects.

### Added — Artwork on every product, and on every batch (packaging)

- **Products → Artwork**: the company's own brands now carry artwork the way
  a client's already did — label, tube, bottle, carton, pouch — uploaded as
  a picture or PDF, versioned, approved in-house, superseded when the next
  version is approved. The **batch page** shows the approved artwork for
  its product (the client's on a third-party job, the company's own
  otherwise) and marks anything still awaiting approval; the **floor
  production screen** shows the packing line the approved pack, picture
  first, for the batch it is working on; the management view shows it on
  every batch on the shelf. Artwork opens through one authorised route for
  anyone who may see the product, the client or production.

### Added — Backup download and restore upload (issue #19)

- **Administration → Data → Download a backup**: the whole ERP as one zip —
  every table (people and roles, facilities and stores, materials,
  formulations, stock and its history, batches, dispatches, approvals, the
  audit trail) and every uploaded bill, photo and artwork — with a manifest
  of when, from what build and how many rows. **Restore from a backup** puts
  all of it back exactly as the file holds it, in one transaction: old
  entries, employees, stores, stock history, uploads; whatever was entered
  after the backup is removed; new records number on from the restored
  ones. The audit trail only ever grows — entries in the backup that are
  missing are added, what is there stays — and the person restoring is
  never removed, so they stay signed in. A copy of what is there now is
  kept on the server under `backups/` before anything changes. A backup
  from a newer build is refused until the ERP is updated. Super Admin only,
  company name typed to confirm. The same runs headless as `erp:backup` and
  `erp:restore`, for a nightly cron and a server console.

### Added — Engineering store (issue #18)

- **Engineering Store** is a store category a facility can add (Facility →
  Stores → kind _Engineering Store_), seeded as `ENG` on deploy. Spares and
  maintenance consumables are received into it; the goods receipt screen
  offers it for consumables alongside the raw material and general stores.

### Added — Dispatch, with the e-invoice on record before anything leaves (issue #17)

- **Dispatch → Dispatches**: finished goods leaving a facility against a
  tax invoice. A consignment is written up from a **finished goods store
  only**, batch by batch, priced as the invoice carries it; the tax follows
  the states (IGST between states, CGST + SGST within one) from the seller's
  GSTIN and the buyer's. Only **QC-released, unexpired batches** are offered,
  and a **client's batches go only to that client** — the customer linked to
  them — while our own go to anyone. Nothing moves when it is written up.
- **E-invoicing is mandatory** for a GST-registered buyer: the goods do not
  leave until the invoice number and date, the **IRN and acknowledgement**
  the IRP returned, and the **signed invoice with the QR** are all on
  record. The ERP prepares the e-invoice as **JSON in the IRP's own schema
  (INV-01 v1.1)** — seller, buyer, place of supply, HSN, GST unit codes,
  batch and expiry, values to the paisa — for the bulk upload tool or a GSP;
  the IRN it returns is recorded here. An unregistered buyer (B2C) is
  invoiced without an IRN, with the plain invoice uploaded instead.
- When the goods go, the **stock leaves through the ledger** (`SALES_DISPATCH`)
  against the consignment, with the transporter, vehicle, LR and e-way bill
  recorded and a **delivery challan / packing list** printed. A dispatched
  consignment cannot be cancelled; it is marked delivered.
- **Dispatch → Customers**: who goods are billed to and sent to — a contract
  client (linked to their client record), a marketplace, a distributor — with
  GSTIN, billing and shipping addresses. One GSTIN is one customer.
- **History for management**: every consignment with its status, customer,
  invoice, IRN, transport and value, searchable by number, invoice, IRN,
  vehicle, e-way bill or customer, filtered by status, customer, facility
  and period, with totals for the view. Every paper that travelled — invoice,
  signed e-invoice, e-way bill, LR — is kept against it.
- **Facilities bill under their own company**: a facility now carries a
  legal name; the factory's invoices name Harbanshram Bhagwandas Ayurvedic
  Sansthan, the depot's name HRBD. Set it under the facility's details.
- **Dispatch Manager** role: everything a consignment needs — write up,
  invoice, dispatch, deliver, cancel, customers — and nothing of what made
  it: no planning, no production, no recipes. Director, Management,
  Accounts, Sales and E-commerce see dispatches; only the Dispatch Manager
  (and the administrators) act on them.
- `ERP_EINVOICE_MANDATORY` (default `true`) and `ERP_COMPANY_LEGAL_NAME`.

### Changed — Rahat Rooh is named as the company's own brand (issue #14)

- Wherever stock, a batch or a product was "our own", "our company" or
  "own brand", it now carries the company's own brand name — **Rahat Rooh**
  by default, `ERP_OWN_BRAND` to change it: the material-owner choice on a
  delivery, the brand on a material's batch history (with the supplier named
  under it), the product's "manufactured for", the batch trace, the QC and
  receipt pages, and the plan's material source.

### Added — Search inside long lists (issue #15)

- Any list with more than ten entries gets a **search box at the top**:
  type any words in any order and the list narrows as you type. Used for
  the material on a delivery, on opening stock and on a transfer, the
  formula on a plan, and the customer and product on a dispatch.

### Changed — The delivery screen says what it is for (issue #16)

- **Add Material Inventory** (was "New goods receipt"), with the sections
  **Inventory Details** (was "Delivery") and **Description** (was "Lines").

### Added — The launch-day rehearsal

- A test that walks launch day end to end the way the factory will do it:
  opening stock from the counting sheets for raw material, packaging and
  finished goods; a third-party batch planned for HRBD; the short material
  requested, delivered and QC-released; the batch made, QC'd and landed in
  HRBD's name; Rahat Rooh stock moved to the Paper Market depot on a
  challan and booked in; HRBD's goods dispatched to HRBD from the factory
  and Rahat Rooh to a marketplace from the depot, each with the e-invoice
  on record first; and management seeing all of it — every step through
  the screens, under the roles the people will hold.

### Fixed — Planning says what went wrong, to the person who can fix it

- A **packaging material deleted after a product's pack list was written** no
  longer stops a plan. The line is left out, the plan is checked on
  everything else, and a warning names the product whose pack list needs
  mending — the same treatment a deleted recipe material already got.
- The recipe version and the batch unit a plan was raised against are checked
  before the stores are, so a plan whose formula version has gone says so in
  plain words instead of failing somewhere deeper.
- A material with **no stock unit** is named rather than read off a blank.
- When planning does hit a fault it cannot name, the **system administrator
  now sees what the fault actually was** — its type, its message and where it
  happened — beside the reference, instead of having to read a server log.
  Everyone else still sees only the reference and the plain advice. The
  message also says plainly that nothing was saved, so trying again is safe.

### Added — Clearing what testing left behind, and a worked example (issue #12)

- **Administration → Data**, the system administrator's alone. Two cards:
  fill the ERP with a worked example, and clear what a trial put in.
- **Clear test data** is chosen kind by kind, not all or nothing: plans and
  batches, deliveries and QC, stock and movements, approvals and documents,
  formulations, materials and products, vendors and clients, facilities and
  stores. Stores stay unless facilities are ticked, so the factory survives
  a clear-out of the trial that was run in it.
- What a choice **drags in with it is named before it happens**: the
  dependencies are the foreign keys, not a matter of taste — clearing stock
  clears the deliveries that brought it in, and clearing materials clears
  everything made from them. Live counts sit beside each kind. The whole
  clear is one transaction: all of it, or none of it. Document numbering can
  be restarted with it.
- **Never touched, whatever is ticked:** people, their roles and permissions,
  units, departments, facility and store categories, and the audit trail —
  which is the record that the clearing itself happened, and is written with
  who did it and what went.
- Confirmation is the **company name typed in full**, and the screen is
  reachable only by the system administrator.
- **Fill with demo data** builds one worked example end to end: a facility
  with four stores, two vendors and a contract client, three raw materials,
  two packaging materials, a product with its bill of materials, an activated
  formula, opening stock, a delivery taken through QC, a third-party plan with
  its material requests, a completed batch and an open stock count. It creates
  **no login accounts** — the people on the system are the real ones.
- Also from the terminal: `php artisan erp:reset --scope=… --restart-numbering`
  and `php artisan erp:demo-data`.

### Changed — Less in the left menu; receiving starts in the store (issue #13)

- **Batches**, **Stock Counts** and **Goods Receipts** have left the menu.
  Nothing was removed from the ERP — each has a home where the work actually
  happens:
    - a material's batches are on the material's own page (issue #8), with
      brand, QC status, dates, rate and where each one sits;
    - stock counts stay reachable and keep working, but no longer take a line
      in a menu the factory does not use them from;
    - **deliveries are received from the store they arrive into**. The store
      page now carries **Receive into this store**, which opens the receipt with
      that store already filled in, and **Past deliveries** for what has been
      booked before. Quarantine stores offer neither: nothing is received
      straight into quarantine.
- The receipt itself is unchanged and still asks the short way round — upload
  the supplier's bill if there is one, or type the lines, and say which brand
  the material belongs to when it is a client's.

### Added — Old stock entry (issue #7)

- **Store → Old Stock Entry**, for the system administrator only: the raw
  materials, packaging materials and finished goods already on the shelf
  when the ERP goes live, booked from one screen into the right store of
  the factory. Three sections, one per store; lines are typed in or come
  from a filled-in sheet (a template per section lists every material on
  file with its code and unit; rows are matched by code, else by name, and
  what does not match is reported row by row before anything is posted).
- Every batch booked as opening stock is **QC passed automatically**, on
  record with who booked it, and usable at once; the expiry comes from the
  shelf life when the sheet leaves it blank. One opening-balance posting per
  store, all or nothing, listed on the screen once booked.
- The facility's opening-stock switch still governs it: close it under the
  facility's Settings once the old stock is in.

### Changed — Bills are read on the server; Claude is optional

- **Built-in bill reader.** A PDF bill printed from the supplier's billing
  software (Tally, Busy, Marg, Zoho and the like) is now read here, on the
  server, with no key and no network: the seller and their GSTIN, the bill
  number and date, and each goods line with HSN, quantity, unit, rate,
  amount and batch. It is proven on the two real bills the company shared.
  `ANTHROPIC_API_KEY` is no longer needed for Upload bill.
- **Photos and scans** carry no text, so they still need the Claude bill
  reader. Without a key the screen says so and asks for the supplier's PDF
  instead. With a key, photos go to Claude, and Claude also has a go at a PDF
  the built-in reader could make nothing of.
- Every extraction says which reader produced it.

### Fixed — QC sticker

- The QR code and the QC APPROVED stamp overlapped the material name and the
  batch number; the sticker now has the words on the left and the QR with
  the stamp under it in their own column on the right, on one page.

### Fixed — Posting a receipt no longer fails with a server error

- Posting a delivery that needs QC at a facility with no quarantine store used
  to fail with a server error. It now keeps the receipt as a draft and says
  what is missing ("no quarantine store at City Depot: add one under
  Facilities, or receive at a facility that has one"). Quarantine is now the
  one at the facility the delivery arrived at, never another plant's.
- A raw material can no longer be booked into a finished goods store, nor
  packaging into a raw material store: the store list on the receipt follows
  what is on the lines, and the server refuses a mismatch with a plain
  message instead of failing.

### Added — Upload the bill from the Raw Materials page; add what is not on file

- **Upload bill** on the Raw Materials and Packaging Materials pages sends
  the supplier's bill to the reader and opens the receipt filled in from it,
  each line matched to the material on file by code, HSN, name or INCI name.
- A bill line that matches nothing can be **added as a new material** without
  leaving the receipt: name, HSN, unit and rate come from the bill, the code
  is the next free one, QC is on by default, and the reorder level defaults
  to this delivery's quantity. The new material goes on the receipt, through
  QC, and into the store like any other; its page can be completed later.
  Adding a master needs `raw_material.create` / `packaging_material.create`.

### Added — Scorecards, OTIF, process performance and the ERP assistant

- **Department scorecards** under **Overview → Scorecards**, for 7, 30, 90
  or 365 days and per facility. Planning: plans raised, plans that became
  batches, batches started on the planned date, plans past their date.
  Purchase: requests raised, delivered by the need-by date (lead-time
  adherence), request-to-delivery days, open past need-by. Stores: deliveries
  booked, bill-to-booked hours, **inventory accuracy** from approved stock
  counts, transfers received by the expected date. QC: decisions, turnaround
  hours, rejection rate, samples waiting too long. Manufacturing: batches
  completed, yield, start-to-finish hours, batches running too long.
  Packaging: batches and units packed, packaging rejection (wastage against
  packaging used). **Dispatch OTIF**: client jobs completed on time (by the
  delivery date) and in full (units posted reached the order), with the
  on-time and in-full parts and jobs overdue now. Each department carries a
  score; the factory score is their average.
- **Process performance** on the same screen: for each step of the flow
  (plan raised → checked, request → received, delivery opened → booked,
  sample → decision, approved → started, started → completed, dispatched →
  received, count started → approved) how many went through in the period,
  the average and longest time, how many are waiting now and how long the
  oldest has waited. The process is measured, not the person.
- **Ask the ERP** under **Overview → Ask the ERP**: questions in plain words
  ("Do we have enough Surfactant A for next week?", "What should purchase
  order today?", "Where did batch RM-0001 go?", "How did QC do last
  month?") answered by Claude from the ERP's own data through ten read-only
  tools: material search, stock outlook, reorder advice, exception feed,
  command centre, order status, batch trace, scorecards, stock risks and
  client profitability. Every tool runs under the asking person's own
  permissions; the assistant never changes anything and says which figures
  it read. Needs `ANTHROPIC_API_KEY`; `ERP_ASSISTANT_ENABLED=false` switches
  it off; `ERP_ASSISTANT_RATE_PER_MINUTE` limits questions per person.
- **Permission** `assistant.view`, held by the management and manager roles.

### Added — Shop floor: scan before issue, stock counts, the mobile floor mode

The floor gets a phone-sized ERP and the ERP gets a check at the kettle.

- **QR codes on everything that moves.** Batch stickers carry a QR, stores
  and racks print labels (**store page → Print labels**), and every
  manufacturing order prints an A5 **batch card** with its own QR and the
  material list. Codes read `LOT:<batch>`, `MO:<order>`, `LOC:<store>/<rack>`,
  `ITEM:<code>`; the QR points at `/floor/scan?c=<code>`, so any phone camera
  opens the right thing.
- **Scan before issue.** At the kettle each drum is scanned against the
  batch. It passes only when the material is on the recipe, the batch has
  been released by QC, it has not expired, it belongs to whoever the batch is
  for, and it is the batch the store reserved for this order. Anything else
  is **blocked, with the reason, and recorded** (`manufacturing_order_scans`)
  — wrong ingredient, wrong batch, expired stock and the wrong owner's
  material are caught before they go in. The order page shows what has been
  verified and the blocked attempts. With `ERP_REQUIRE_SCAN_BEFORE_START=true`
  a batch cannot start until every raw material has passed a scan.
- **Stock counts** under **Store → Stock Counts**: a count freezes what the
  system says a store holds, batch by batch; the counter scans each batch and
  enters what is on the shelf (a batch found on the shelf but unknown to the
  system is added with a zero system quantity); once submitted, **someone
  other than the counters approves it** and every difference is posted as a
  stock adjustment through the ledger, referencing the count. **Inventory
  accuracy** (the share of lines where shelf matched system) and the value
  of the differences are read from the lines.
- **Floor mode** at `/floor`: one column, big targets, no sidebar; a
  camera scanner (typed entry where the browser cannot scan); scan anything
  to see what it is and what you may do with it; issue by scan; record the
  production stage and progress; take a photo against a batch, an order or a
  count. Installable as a home-screen app (web manifest and a shell-only
  service worker; data is never cached offline).
- **Permissions.** `inventory.count` (Warehouse Manager, Store Executive)
  starts and records a count; `inventory.approve_count` (Warehouse Manager)
  approves one.

### Added — Factory intelligence, first layer: stock that says what to do

The first step from a recording ERP to a deciding one. Every figure comes
from the ledger and the planning documents; nothing is estimated that can
be read.

- **Predictive reordering.** For every raw and packaging material: on hand,
  reserved, usable (released by QC, not in quarantine, not expired), what
  approved and running batches still need beyond their reservations, what
  checked plans will take, what purchase has already requested, the rate of
  use over the last 90 days, the supplier lead time, the days of cover and
  the run-out date. From these: the shortfall, the recommended order quantity
  (rounded up to the minimum order quantity and the pack multiple, capped by
  the maximum stock), the date to order by, and a status — _Order today_,
  _Order this week_, _Watch_ or _Covered_. Under **Planning & Purchase →
  Reorder Advice**, most urgent first, with a purchase value at last prices.
- **Smart purchase recommendation.** Each recommendation names the supplier
  with the best price per stock unit in the last year, their last price,
  their measured lead time (material request to receipt), how many
  deliveries they have made and when the last was, and what is already
  pending.
- **The outlook panel on every material's page** reads as sentences:
  "42 KG on hand. 18 KG already reserved. 24 KG usable. Upcoming production
  requires 67 KG, the first of it by 21 Sep. Shortfall: 43 KG. Supplier lead
  time: 5 days. Recommended action: order 50 KG by 16 Sep from Vendor ABC
  (last price ₹118.00 per KG)."
- **Slow-moving stock** under **Store → Slow-moving Stock**: every batch
  idle for 30, 60, 90 or 180+ days — idle since its last issue, or since it
  arrived if it never was — valued at the batch's landed cost (the standard
  cost when the batch has none), with the blocked working capital per bucket.
- **Expiry risk** under **Store → Expiry Risk**: for every batch expiring
  within 180 days (or 30 / 60 / 90 / 365), the expected use before expiry at
  the material's rate after the batches that expire sooner are used first,
  the coverage, and the quantity and value at risk. "Aloe Extract lot X will
  expire in 40 days; expected consumption before expiry is only 40%.
  ₹6,000 of inventory at risk."
- **A dashboard tile, _Materials to order_**, with the headline sentence when
  something would run out before a delivery could land.
- **Ordering terms on the masters**: minimum order quantity and order
  multiple on materials; lead time on vendors. Settings in `config/erp.php`
  under `intelligence` (consumption window, planning horizon, default lead
  time, buckets, risk window).

### Added — Controls: approvals by risk, maker-checker, signatures, reversals, recall, documents

- **Approval by risk, not just amount.** A manufacturing release goes for
  a second signature when a trigger fires: the batch is on a recipe
  version that is not the active one, a client job would lose money or has
  no terms, the product's recent yield is below target, a material it
  uses shows abnormal wastage or an unexpected price increase, or the
  person releasing it raised it. A recipe version is always signed off by
  someone other than its author. Releasing a lot QC rejected is an
  override and needs a second signature.
- **Maker-checker with an approving authority.** Each employee can be
  given an approving authority (on their record, at creation or later);
  that person is told when the employee raises a request and may sign it,
  as may anyone holding the workflow's permission — never the requester.
  The person who booked a delivery in cannot release it from QC.
- **Digital signatures.** Every approval decision is written once with a
  keyed hash over who, what, when, on which record; the approvals screen
  shows each signature and whether it still verifies.
- **Approvals screen** (Overview → Approvals): what is waiting for your
  signature, with the triggers, and the signed record of recent decisions.
- **Immutable critical records, corrected by reversal.** A posted stock
  movement is never edited; a reversal posts the opposite lines and both
  stay in the ledger pointing at each other (Store → Batches → Reverse,
  for those with `inventory.reverse`). `erp:lock-audit-trail` now also
  revokes UPDATE and DELETE on the ledger, approval actions, stage events
  and batch adjustments at the database.
- **Recall management** (a batch's page → Recall trace): from a defective
  lot, every batch it was consumed into, the finished goods those made,
  where each affected batch sits now, where it was transferred, what has
  already been dispatched and which client owns it; and backwards, what a
  finished batch was made from and who supplied it.
- **Controlled documents** (Quality Control → Controlled Documents): SOPs,
  specifications, artworks, certificates of analysis, formula documents
  and QC standards with version history. A new version is a draft;
  approval by someone other than its author makes it current and
  supersedes the previous one; withdrawn and superseded versions stay on
  file. A manufacturing order lists the approved current documents for
  its product. New permissions under `document.*`.

### Added — Production analytics: batch stages, consumption, yield, cost, client margin, what-if, capacity

- **Real-time batch progress.** Instead of "in production", the floor
  records the stage — weighing, charging, mixing, heating, cooling,
  in-process QC, filling, packaging — and how far through it is. A stepper
  and a progress bar on the order, the stage on the dashboard's production
  list and in the command centre, and an append-only trail of readings.
  Starting a batch opens it at Weighing; completing it closes it at 100%.
- **Returns and wastage on a batch.** Material issued but not used goes
  back to the store through the ledger (`PRODUCTION_RETURN`, against its
  batch); material lost is recorded as wastage. Both are capped at what
  was issued.
- **Material consumption intelligence.** Per batch: standard vs issued vs
  consumed vs returned vs wastage vs actual, with the variance flagged over
  the threshold. Across batches, under **Manufacturing → Production
  Analytics**: "Preservative consumption has been 4.8% above standard in
  the last six batches", with a bar per batch.
- **Yield analytics.** Per product: planned against actual output and the
  leakage in units — "Expected 10,000 units, produced 9,620 units — yield
  96.2%, 380-unit variance."
- **Cost variance engine.** Per batch, for those who may see costing:
  standard cost against actual, explained by raw-material price change,
  extra consumption, raw-material wastage, packaging wastage, additional
  charges and low yield, largest first, with the sentence that sums it up.
- **Client profitability** under **Third-Party Manufacturing → Client
  Profitability**, and on each client's page: revenue, manufacturing
  charge, testing, freight, raw material cost, packaging cost, wastage and
  margin per client, product and batch. Clients losing money are counted.
- **What-if production simulation** under **Planning & Purchase → What-if
  Simulation**: choose a formula, a quantity, a facility and an earliest
  start; see raw material and packaging requirements, shortages, the
  purchase value at the best known price, the expected material cost per
  unit, the machine time, the completion date and which booked batches it
  would push. Nothing is written.
- **Capacity planning** under **Planning & Purchase → Capacity**: each
  manufacturing facility's daily capacity (new field on the facility)
  against booked production, week by week, with the bookings behind it.

### Added — Command centre, exceptions, notifications, escalation

- **Factory command centre** (Overview → Command Centre, for anyone who may
  see reports): one screen answering what is running (with hours on the
  floor), what is delayed, what is waiting for QC (with hours waiting),
  what is short (by material, with the plans and requests behind it), what
  is dispatching today (client batches due and finished goods awaiting
  dispatch), what requires a signature (transfers to approve, QC on hold,
  plans ready to release, approvals), and what could stop production
  tomorrow (materials that cannot be covered in time, batches reserved
  against stock still in quarantine). Refreshes itself every minute.
- **Exception-based management.** Twelve rules read the floor and report
  only what crossed a threshold: slow QC, delayed batch, plan not started,
  material request unfilled, could stop production, transfer late, stock
  discrepancy, unexpected price increase, unusually high rejection,
  production below target, high material variance, abnormal wastage.
  Thresholds are in `config/erp.php` under `exceptions`. The dashboard shows
  the top few for those who may see reports; the command centre shows all.
- **Notifications.** A bell in the header with the unread count and the
  latest few; a notifications page; opening one marks it read. Stored in
  the database, so other channels can be added later without touching the
  logic that raised them.
- **Automatic escalation.** If an exception stands too long, the roles
  responsible are told; longer still, their seniors: QC pending 6 hours →
  QC Manager, 12 hours → Factory Manager; material request unfilled 24
  hours → Purchase Manager, 72 hours → Factory Manager and Director; and so
  on for every rule, in `config/erp.php` under `escalation`. Each level
  fires once per exception and closes when the condition clears.
  `php artisan erp:escalate` runs hourly on the scheduler.

### Changed — Dashboard clock

The greeting shows the date and a clock ticking every second in the
factory's time zone (`ERP_TIMEZONE`, Asia/Kolkata by default), and the
moment the person signed in and from where. Timestamps are still stored in
UTC.

### Added — Third-party / contract manufacturing (issue #6)

Manufacturing for other brands runs through the same planning, stores, QC,
manufacturing and packaging as our own. Nothing is duplicated: a batch is
either _Own brand_ or _Third party_, and a third-party batch carries the
client with it everywhere.

- **Contract client master.** Code (`TP-001`, given on save), company and
  legal name, GSTIN and PAN, contact, billing and shipping addresses, payment
  and credit terms, agreement reference and validity, notes, active flag.
  A client with history cannot be removed, only made inactive. The client's
  page gathers everything of theirs: jobs, material with us reconciled,
  finished goods awaiting dispatch, products, formulas, artwork approvals,
  QC specifications and the billing summary of completed jobs.
- **Products say whom they are made for**, and **formulas say whose they
  are**: company owned, client owned or joint. A client-owned or joint formula
  can only ever be planned for that client; it stays behind the formula PIN
  like every other.
- **Planning asks whose batch it is.** A third-party plan names the client,
  their PO / work order, their product name, the required delivery date and
  the material source: ours, the client's, or mixed with the client-supplied
  materials ticked. Everything else about planning is unchanged.
- **Client-supplied material is the client's.** A goods receipt can be booked
  in a client's name; its batches are then owned by that client. Ownership
  runs through availability, reservation and consumption: an own-brand batch
  never draws on a client's stock, a client's job never draws on another
  client's, and a client-supplied line counts only that client's own batches.
  A shortage of client-supplied material shows as _Awaiting client material_
  and is never turned into a purchase requirement. The store pages show what
  in each store is client-owned.
- **Manufacturing orders** carry the client, PO, delivery date and material
  source from the plan; approval holds client-supplied lines from the
  client's batches alone; the finished batch is posted as the client's, with
  a batch number that says so (`TP-001-260913-001`). Third-party jobs sit in
  the same order list and dashboard pipeline, badged _THIRD PARTY — ABC
  WELLNESS_, and can be filtered by type and by client on the plan and order
  lists.
- **Job costing and client billing.** Commercial terms per order —
  manufacturing charge per kg or per unit, whether our material is billed and
  at what markup, testing / development / artwork / freight / other charges,
  GST — and the job's figures: our raw material and packaging at actual batch
  cost, the value of the client's material (never charged), chargeable
  amount, GST, total, and the margin over our material. Kept apart from
  own-brand costing.
- **Client material reconciliation**: per material the client supplied,
  what production took (this job and all jobs), wastage, and the balance
  still with us — from the ledger, batch by batch.
- **Artwork approvals** per client and product: label, carton and bottle
  versions with the client's sign-off date, approver and document; approving
  a version supersedes the previous one; a job's page says when no approved
  artwork is on file.
- **Client QC specifications** per client and product; the QC Checkpoint
  shows them on every batch owned by that client.
- **Dashboard**: a third-party section with active client batches, material
  awaited from clients, client goods awaiting dispatch, this month's output
  for clients and delivery dates due.
- New permission module _Contract Clients_ (`client.*`): Factory Manager and
  Management create and edit; Sales Manager has it all; most other roles view.

### Added — Stock transfers: scan the challan to book in (issue #5)

- **Dispatch prints a transfer challan.** Once a transfer is dispatched the
  source prints an A4 challan: from and to (facility, store, address,
  GSTIN), vehicle, the batches and quantities on the lorry, signature boxes,
  and a QR code with an eight-character inward code printed under it. Only
  someone working at the source facility can print it, and the code never
  appears on a screen.
- **Nothing lands at the destination until the challan is scanned.** On the
  transfer's page the receiving store scans the QR (a handheld scanner, or
  the phone camera, which opens the page with the code on the URL and
  verifies at once) or types the code as printed. A wrong code, or the
  challan of another consignment, is refused. Only then can the quantities
  be booked in, and only then does the stock show at the destination store.
  The gate is enforced in the service, so no screen or route gets round it.
- **The transporter's paperwork can stand in for the code.** The invoice or
  LR that came with the lorry can be uploaded; Claude reads it for the
  transfer number and inward code, and the transporter, LR number and
  vehicle are recorded on the transfer. Paperwork for another consignment is
  refused and not kept. Without the API key the code alone verifies. The
  document stays attached to the transfer and opens from its page.
- The transfer's progress shows a _Challan scanned_ step with who scanned it
  and when. Consignments already on the road when this deploys are given a
  code by the migration (or when their challan is first printed), so nothing
  is left stuck in transit.

### Added — Goods receipts off the supplier's bill (issue #3)

- **Upload the bill, get the receipt.** The goods receipt screen takes the
  supplier's invoice or delivery challan as a PDF or a photo. Claude reads
  the vendor, invoice number and date, totals, and every line with its
  quantity, unit, rate, batch number and manufacturing / expiry dates. The
  ERP matches the vendor by GSTIN (then by name) and each line to the item
  master by code, HSN and name, and the form opens filled in with the bill's
  wording shown under each line. The bill stays attached to the receipt and
  opens from its page.
- **Only the plant head keys a receipt in by hand.** A new ability,
  _Procurement → Receive manual_, is held by the Factory Manager (and Super
  Admin) by default; the Roles screen can grant it to others. Everyone else
  books receipts from the scan: quantities, rates and batch details are the
  bill's and cannot be typed over; they choose the item and unit where the
  reader could not. Without a bill their receipt is refused.
- **Add a vendor from the receipt screen.** A vendor the bill names but the
  ERP does not know is added in a pop-up on the same page — name and GSTIN
  from the bill, the rest later on the vendor's page — and selected at once.
  A duplicate GSTIN is refused. Store Executives may add vendors for this.
- Bill dates are read day-first, as Indian bills print them. Freight and
  other lines without a quantity are shown but never become stock.
- Needs `ANTHROPIC_API_KEY` (and optionally `ERP_AI_MODEL`, default
  `claude-opus-5`) in the environment. Without it the screen says so.
- **Checked against two real bills** (a Caldic proforma and a Tally
  e-invoice from Fragrance Specialities): the reader is told where the
  seller and the buyer sit on Indian bill layouts, to read the buyer's GSTIN
  separately, how Tally prints batch numbers and dates such as `28-Feb-26`,
  and that a pack size is not the quantity. Our own GSTIN
  (`ERP_COMPANY_GSTIN`) is never accepted as a vendor's; a proforma or
  quotation is flagged as provisional on the receipt screen. The command
  `php artisan erp:read-bill <file>` tries the reader on any bill from the
  shell and shows what it found and how it matched, without booking anything.

### Added — QC assignments and labels (issue #2)

- **Receive stock from the item's own page.** Raw materials, packaging and
  products show what is free in the stores, what is held in quarantine and
  every inspection still waiting at the QC Checkpoint, with a _Receive stock_
  button that opens a goods receipt with the item already on line one. A
  delivery lands in quarantine; QC releases it into the store.
- **QC slip at the checkpoint.** A decided inspection prints an 80 mm
  _QC PASSED_ / _QC REJECTED_ slip with the QC reference, batch, quantity,
  what was measured, who decided and where the stock goes.
- **Batch sticker for the store, 80 mm wide** (was 100 × 70 mm), printed by
  the store once the batch is put away. Both sizes live in
  `config/erp.php` under `labels`.
- **Carton labels for finished goods.** The finished goods store records
  how a batch is boxed — number of boxes, units per box, gross weight, first
  box number — and prints one A5 label per carton: product, net quantity,
  batch number, manufacturing date, expiry, gross weight, MRP and
  "Box 3 of 12". The plan is saved on the batch so a reprint is identical.
- **QC decisions are signed with a personal PIN.** Approve and reject ask
  for the person's PIN (the same PIN that unlocks formulations), set under
  Settings → Security. Wrong guesses are counted and lock the PIN for a
  while; someone without a PIN is told where to set one. Switch off with
  `ERP_QC_REQUIRE_PIN=false`.
- Authorisation on a QC decision is answered before validation, so someone
  without the right sees a refusal rather than a request for a PIN.

### Added — Shop-floor roles (issue #4)

- **QC Executive, Store Executive, Packaging Executive and Production
  Operator**: each holds one job's permissions, so the navigation shows only
  that job's screens and every other route refuses them. A QC Executive
  reaches the QC Checkpoint alone; a Packaging Executive sees orders ready
  to pack and records packaging use; a Store Executive receives and moves
  stock at the stores they are assigned to; a Production Operator starts
  approved orders and records consumption. Managers and Super Admin keep
  full control.
- A role new to the code is created with its full defaults by
  `erp:sync-permissions` on the next deploy, without touching roles an
  administrator has tuned.

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
