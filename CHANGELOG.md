# Changelog

Notable changes to HRBD ERP. Newest first.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project is pre-release; versions begin at 0.1.0 and the schema may change
between them.

---

## [Unreleased]

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
