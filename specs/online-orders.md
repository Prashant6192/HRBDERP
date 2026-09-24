# Spec — Online orders: marketplace labels, packing by scan, returns

Status: agreed plan, not yet built. Build in three pull requests, in the order
below, each verified on the preview environment before the next starts.

Problem being solved: at the Delhi depot (Paper Market), labels for Meesho,
Flipkart, Amazon and Myntra orders arrive on WhatsApp every morning, get
printed by hand, and a printed label can be packed or quietly forgotten with
nothing to show for it. Stock is never reduced. Returns are not recorded.

## Facts from the real label samples (24 Sep 2026)

- **Meesho "Sub Order Labels" PDF**: A4, one label + tax invoice per page, iText
  producer, **has a text layer**. Per page the text carries: courier name
  (`Delhivery`, `Shadowfax`, `Xpress Bees`, `Valmo`), payment mode (`COD: Check
  the payable amount on the app` / `Prepaid: Do not collect cash`), AWB
  (12–16 alphanumerics under the barcode: all digits for Delhivery and
  Xpress Bees, `SF` + digits + `FPL` for Shadowfax), the "Product Details" row
  (`SKU | Size | Qty | Color | Order No.` — e.g. `Medicated oil 300 ml | Free
  Size | 1 | NA | <18 digits>_<line no>`), then the invoice: `Sold by`, `GSTIN -
  <seller GSTIN, Delhi 07>`, `Purchase Order No.`, `Invoice No.` (five letters + digits), `Order
  Date`, `Invoice Date`, description with HSN `300390`, and the final `Total`
  line with the payable amount. Return address printed on the label is the
  Paper Market depot. The barcode is Code 128.
- **Myntra label**: letter size, "Microsoft: Print To PDF", **image only, no
  text layer**. Carries courier code (`EK_E2E`), AWB (`MYEC` + 10 digits),
  `AMOUNT TO BE PAID Rs.<amount>`, `COD`, buyer address, **no product and no
  quantity**. Return address is the Rudrapur factory.
- Consequences: a per-marketplace text parser handles Meesho (and, once
  samples arrive, Flipkart and Amazon); image-only pages go to the existing
  Claude document reader (`App\Support\Ai\ClaudeDocuments`); a shipment may
  legitimately have **no product yet** and must not be packable until one is
  assigned; returns must be receivable at **any facility with `can_return`**.
- The real samples contain customer names and addresses. **Do not commit
  them.** Build test fixtures with the same layout and invented data.

## The protocol

| Step | Who | Screen | Effect |
| --- | --- | --- | --- |
| Upload | E-commerce agency (own restricted login, per brand) | Dispatch → Online orders → New upload | PDF split per page → one **shipment** per label; parsed; SKU matched to product via listings; stock **reserved** in the brand's default FG store; accountant notified (bell + email). Agency presses **"That's all for today"** to close the upload. |
| Check | ERP | same | Flags: unknown SKU, no product on label, duplicate AWB, **short stock** ("21 × Medicated Oil 300 ml needed, 6 in Paper Market"). |
| Print | Accounts Manager / Dispatch Manager | Online orders → batch | Print all / by courier / only unprinted. Labels assembled **in the browser** from the stored originals, **sorted by courier**. Each print logged. Shipment → `printed`. |
| Pack | Store Executive / Dispatch person | Floor mode → Pack | Scan the label barcode (Code 128, already supported by `scanner.tsx`). Shows product name + photo large for confirmation. Shipment → `packed`; **stock leaves the ledger now** (reservation consumed, lot FEFO). |
| Hand over | same | Floor mode → Hand over | Scan each parcel, or "hand over all packed for {courier}". Prints a **handover sheet** per courier (count + AWBs) for the courier's signature. Shipment → `handed_over`. |
| Cut-off | ERP scheduler | Dashboard, bell, escalation | At `ERP_ONLINE_ORDERS_CUTOFF` (default `16:00` IST) any shipment `printed` and not `packed` becomes exception `shipments_not_packed` → Dispatch Manager, escalates per the existing ladder. A day cannot be closed while a shipment is `uploaded`/`printed`. |

Agency sees each order's status (printed / packed / handed over) on its own
upload list. Nothing else.

## Decisions taken (defaults; make each a setting where marked)

1. Stock is **reserved at upload, deducted at pack**, never at upload or handover.
2. The agency gets an ERP login with the **E-commerce Agency** role, scoped to a brand.
3. Packing is verified by **phone scan**; a "mark packed" button exists for the Dispatch Manager only, and is audit-logged with a reason.
4. Cut-off `16:00` Asia/Kolkata — `ERP_ONLINE_ORDERS_CUTOFF` (setting).
5. Cleanse Ayurveda: default FG store = Paper Market FG, seller entity set on the Brand master (setting, editable in the UI).
6. Amazon / Flipkart / Myntra readers: AI fallback until real samples are supplied; text parsers added in PR 3.
7. No marketplace API integration. No WhatsApp integration. No second invoice raised by the ERP for marketplace sales (the marketplace's invoice number and value are stored on the shipment).

## Data

New tables (register in `DataResetService` group `online_orders`, folder
`online-orders/`):

- `brands` — `code`, `name`, `legal_name`, `gstin`, `facility_id` (selling facility), `default_dispatch_store_id` (FK warehouses, FG kind), `default_return_store_id`, `default_damaged_store_id`, `is_active`. Seed `RR` Rahat Rooh, `CA` Cleanse Ayurveda. `brand_user` pivot for agency scoping.
- `marketplaces` — `code` (`MEESHO`, `FLIPKART`, `AMAZON`, `MYNTRA`), `name`, `reader` (parser key), `claim_window_hours` (nullable), `is_active`. Seeded.
- `marketplace_listings` — `marketplace_id`, `brand_id`, `seller_sku` (text as printed), `item_id` (FG product), `units_per_order` (default 1), `is_active`. Unique (`marketplace_id`, `brand_id`, `seller_sku`). Created on first mapping of an unknown SKU; remembered.
- `label_batches` — `number` (`LB-yymm-00001`), `brand_id`, `marketplace_id`, `facility_id`, `dispatch_store_id`, `uploaded_by`, `uploaded_at`, `closed_at` (agency's "that's all"), `for_date`, `status` (`open`, `closed`, `done`), `notes`.
- `label_files` — `label_batch_id`, `path` (`online-orders/Y/m/{uuid}.pdf` on `local` disk), `original_name`, `mime`, `size`, `pages`, `sha256` (reject duplicate file), `read_with` (`meesho_text` | `claude` | …), `read_at`.
- `shipments` — `label_batch_id`, `label_file_id`, `page_no`, `marketplace_order_no`, `awb` (unique per marketplace, indexed), `courier`, `payment_mode` (`cod`/`prepaid`/`unknown`), `payable_amount`, `marketplace_invoice_no`, `invoice_date`, `customer_name`, `customer_state`, `seller_sku_text`, `quantity`, `item_id` (nullable until mapped), `units` (quantity × units_per_order), `lot_id` (set at pack), `status` (`uploaded`, `printed`, `packed`, `handed_over`, `cancelled`, `returned`), `printed_at/_by`, `print_count`, `packed_at/_by`, `handed_over_at/_by`, `cancelled_at/_by`, `cancel_reason`, `extraction` (jsonb), `warnings` (jsonb). Reservation via `stock_reservations` (polymorphic, `reservable` = shipment). Ledger posting at pack: new `InventoryTransactionType::MarketplaceSale` (out), reference = shipment.
- `handover_sheets` — `label_batch_id` nullable, `facility_id`, `courier`, `number` (`HO-yymm-00001`), `shipment_count`, `handed_over_by`, `handed_over_at`, `signed_name` nullable.
- `shipment_returns` (PR 2) — `shipment_id` nullable (unknown AWB allowed), `marketplace_id`, `awb_in`, `kind` (`rto`, `customer_return`), `received_at`, `received_by`, `facility_id`, `condition` (`good`, `damaged`, `wrong_item`, `missing_item`), `store_id` (FG or Damaged), `lot_id`, `units`, `photo_path`, `notes`, `claim_status` (`none`, `open`, `won`, `lost`, `expired`), `claim_deadline_at`, `claim_reference`. Ledger: `InventoryTransactionType::MarketplaceReturn` (in), reference = return.

## Reading labels

- `App\Domain\Dispatch\Readers\LabelReader` interface: `read(string $path, int $page): LabelExtraction`. Implementations: `MeeshoTextReader` (smalot/pdfparser per page; anchors above), `ClaudeLabelReader` (existing `ClaudeDocuments::extract` with a JSON schema: courier, awb, payment_mode, payable_amount, order_no, invoice_no, invoice_date, seller_sku, quantity, customer_name, customer_state, return_to, warnings). `LabelReaderRouter` picks by marketplace reader key; falls back to Claude when the text reader finds no AWB.
- Page splitting: server records `page_no` only; the original PDF is never rewritten. Printing assembles pages client-side with `pdf-lib` (add to `package.json`), fetching originals through an authenticated route.
- Test fake: `tests/Support/FakeLabelReader.php`, same pattern as `FakeInvoiceReader`.
- Validation: `files[]` required, `mimes:pdf`, `max:20480` each, max 10 files per upload.

## Roles and permissions

Add to `PermissionCatalogue` module `marketplace` (exists: `view`, `import`,
`reconcile`, `export`): `upload`, `print`, `pack`, `handover`, `return`,
`claim`, `manage` (brands, marketplaces, listings, cancel, force-pack).

- **E-commerce Agency** (new `RoleName`): `marketplace.upload`, `marketplace.view` — scoped to `brand_user`; sees only Online orders and its own batches; no stock, prices, products or other menus. `FacilityAccess` unaffected; brand scoping is a new `BrandAccess` service.
- **Dispatch Manager**: `marketplace.*`.
- **Accounts Manager**: `marketplace.view`, `marketplace.print`, `marketplace.export`.
- **Store Executive**: `marketplace.pack`, `marketplace.handover`, `marketplace.return`.
- **E-commerce Manager**: keeps `marketplace.*`, so it can map listings and see everything.
- Run `php artisan erp:sync-permissions` after the catalogue change (documented).

Menu: Dispatch → **Online orders**, **Returns** (PR 2), **Listings**; Administration → **Brands**.

## Alerts

- `ExceptionService::shipmentsNotPacked()` — rule `shipments_not_packed`, severity high, per facility, after cut-off; template `lateTransfers()`.
- `ExceptionService::shipmentsShortStock()` — rule `shipments_short_stock`, raised at upload when reservation fails, links to the transfer screen.
- Escalation ladder entries in `config/erp.php` `escalation`.
- Command centre tile "Online orders today: uploaded / printed / packed / handed over".
- `ErpAlert` to users holding `marketplace.print` at the facility when a batch closes; email uses the same notification via the mail channel (mail is configured from the password-reset PR).

## PR 1 — Online orders core

Brands, marketplaces, listings, upload + Meesho text reader + Claude
fallback, print by courier (pdf-lib), pack by scan in floor mode, stock
reservation and ledger at pack, handover sheet PDF (dompdf, A4), not-packed and
short-stock exceptions, agency role and brand scoping, listing mapping screen,
docs, changelog, tests (upload → parse → map → reserve → print → pack → ledger
→ handover; short stock; duplicate AWB; agency sees only its brand; cut-off
exception).

## PR 2 — Returns and claims

Receive a return (scan AWB / type order / unknown), condition → store, ledger
in, claim with deadline from `marketplaces.claim_window_hours`, photo, claims
list, returns report by marketplace and reason, works at any `can_return`
facility.

## PR 3 — More marketplaces

Flipkart, Amazon, Myntra text readers from real portal PDFs; order-sheet
(CSV/XLSX) import to fill product + quantity for labels that carry none
(Myntra), matched on order number / AWB; COD reconciliation export.

## Open questions for the owner

- Cleanse Ayurveda's selling GSTIN and stock location (defaults above).
- Cut-off time and who is escalated to (defaults above).
- Sample PDFs from Flipkart, Amazon and Myntra downloaded from the seller
  portals, plus one RTO / return label.
