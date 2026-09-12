# HRBD ERP

An enterprise resource planning system for a cosmetics and personal-care
manufacturer, in one Laravel application. Today it runs the factory from
delivery to finished batch: goods receipts with a QC checkpoint and printed
batch stickers, an immutable inventory ledger with three-level stock alerts,
PIN-protected versioned formulations with Excel import, production planning
that checks the stores and raises material requests, manufacturing orders
that reserve, consume and post finished batches, and a dashboard that shows
where the plant stands. Costing, dispatch and accounting come next — see
[DEVELOPMENT_ROADMAP.md](DEVELOPMENT_ROADMAP.md).

This file is the way in. Each area has its own document:

| Document                                               | What it covers                                                    |
| ------------------------------------------------------ | ----------------------------------------------------------------- |
| [FIRST_DEPLOY.md](FIRST_DEPLOY.md)                     | **Start here to get it online** — a step-by-step first deployment |
| [ERP_PRODUCT_SPEC.md](ERP_PRODUCT_SPEC.md)             | What the system does, module by module                            |
| [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md)               | Every table, and why it is shaped that way                        |
| [SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md)   | Authentication, authorisation, formula protection, audit          |
| [USER_ROLES_PERMISSIONS.md](USER_ROLES_PERMISSIONS.md) | The sixteen roles and what each may do                            |
| [DEVELOPMENT_ROADMAP.md](DEVELOPMENT_ROADMAP.md)       | What is built, what is next                                       |
| [DEPLOYMENT.md](DEPLOYMENT.md)                         | Deployment reference: every option and setting                    |
| [BACKUP_RESTORE.md](BACKUP_RESTORE.md)                 | Backups, and practising the restore                               |
| [CHANGELOG.md](CHANGELOG.md)                           | What changed, when                                                |

---

## The stack

| Layer                | Choice                                                      |
| -------------------- | ----------------------------------------------------------- |
| Framework            | Laravel 13                                                  |
| Language             | PHP 8.3+ (developed on 8.4)                                 |
| Database             | PostgreSQL 16                                               |
| ORM                  | Eloquent, with migrations for every table                   |
| Frontend             | Inertia 3 + React 19 + TypeScript                           |
| Styling              | Tailwind CSS 4                                              |
| Build                | Vite                                                        |
| Authentication       | Laravel Fortify (two-factor and passkeys ready)             |
| Authorisation        | spatie/laravel-permission, with Laravel policies and gates  |
| Cache, queues, locks | Redis in production; database drivers in development        |
| Exact arithmetic     | brick/math over PostgreSQL `NUMERIC`                        |
| Spreadsheets         | maatwebsite/excel + PhpSpreadsheet (formulation import)     |
| PDF                  | barryvdh/laravel-dompdf (batch stickers, material requests) |
| Charts               | recharts                                                    |

---

## Running it locally

You need PHP 8.3+, Composer, Node 20+, PostgreSQL 16 and (optionally) Redis.

```bash
# 1. Dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Database — create the role and databases once
#    (adjust the password, then match it in .env)
sudo -u postgres psql -c "CREATE ROLE hrbderp LOGIN PASSWORD 'hrbderp_local_dev' CREATEDB;"
sudo -u postgres createdb -O hrbderp hrbderp
sudo -u postgres createdb -O hrbderp hrbderp_test   # used by the test suite

# 4. Schema and starting data
php artisan migrate
php artisan db:seed

# 5. Run it
composer dev        # serves the app, queue worker and Vite together
```

Then open <http://localhost:8000>.

### Demo accounts

`php artisan db:seed` creates one account per role so you can see the system
from each angle. **All of them use the password `password`.** They are created
only outside production — `DatabaseSeeder` refuses to seed them when
`APP_ENV=production`.

| Sign in as                 | Role              | What you will see                                                                 |
| -------------------------- | ----------------- | --------------------------------------------------------------------------------- |
| `priya.raman@hrbd.local`   | Super Admin       | Everything, including roles and the audit trail                                   |
| `anil.kapoor@hrbd.local`   | Owner             | Everything operational                                                            |
| `suresh.patil@hrbd.local`  | Factory Manager   | Production, inventory, quality, formulations                                      |
| `deepak.nair@hrbd.local`   | Purchase Manager  | Vendors and the material masters                                                  |
| `imran.sheikh@hrbd.local`  | Warehouse Manager | Warehouses and stock movement                                                     |
| `ritu.malhotra@hrbd.local` | Designer          | Packaging and products only — a good illustration of how tightly access is scoped |

Sign in as the Designer and then as the Super Admin: the navigation, the
dashboard tiles and the reachable URLs all change. The difference is enforced
on the server, not by hiding buttons.

---

## Everyday commands

| Command                                    | What it does                                                                                 |
| ------------------------------------------ | -------------------------------------------------------------------------------------------- |
| `composer dev`                             | Runs the app, queue worker, log tail and Vite together                                       |
| `composer test`                            | Formatting check, then the full test suite                                                   |
| `php artisan test`                         | Just the tests                                                                               |
| `vendor/bin/pint`                          | Formats PHP to the project style                                                             |
| `npm run check:fix`                        | Formats and lints the frontend                                                               |
| `npm run types:check`                      | TypeScript, without emitting                                                                 |
| `php artisan migrate`                      | Applies new migrations                                                                       |
| `php artisan db:seed`                      | Reference data, plus demo data outside production                                            |
| `php artisan erp:sync-permissions`         | Creates new catalogue permissions and hands them to the roles that should have them          |
| `php artisan erp:sync-permissions --roles` | Also resets built-in roles to their defaults (undoes Roles-screen edits)                     |
| `php artisan erp:create-admin`             | Creates or promotes the first administrator                                                  |
| `php artisan erp:lock-audit-trail`         | Makes the audit and formula access trails append-only at the database (`--check` to inspect) |
| `php artisan erp:import-formulations FILE` | Imports a formulation workbook (`--dry-run` to preview)                                      |

### Commands that destroy data

`migrate:fresh`, `db:wipe` and `migrate:refresh` **drop every table**. They are
blocked in production by `DB::prohibitDestructiveCommands`, but nothing stops
them locally. If a development database has anything in it you would mind
losing, back it up first — see [BACKUP_RESTORE.md](BACKUP_RESTORE.md).

---

## How the code is arranged

Business logic lives in `app/Domain/<Module>`, not in controllers. A controller
authorises, validates through a form request, and delegates.

```
app/
  Domain/
    Access/          Permission catalogue and role definitions
    Approvals/       The one approval engine every module uses
    Audit/           Append-only audit trail
    Identity/        Departments, employee account state
    MasterData/      Items — raw materials, packaging, finished goods
    Measurement/     Units of measure and the conversion service
    Procurement/     Vendors, goods receipts
    Warehousing/     Facilities, stores, locations, employee assignments
    Inventory/       Ledger, lots, balances, reservations, transfers, opening stock
  Http/
    Controllers/     Thin; one per module
    Requests/        Validation
    Middleware/
  Policies/          Authorisation, one per module
  Support/           Cross-cutting helpers (list queries)
resources/js/
  components/        Reusable interface pieces, including the DataTable
  pages/             One directory per module, mirroring the routes
```

`App\Models\User` stays where Laravel expects it, because the framework wires
authentication to it directly. Every other model lives in its domain.

Two rules that matter more than the rest:

- **Money and quantities are never floating point.** They are `NUMERIC` columns,
  handled as strings, and calculated with `brick/math`. See
  [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md).
- **Nothing converts units by hand.** Every conversion goes through
  `UnitConversionService`, which is the only place that knows a kilogram is a
  thousand grams.

---

## Testing

The suite runs against a real PostgreSQL database (`hrbderp_test`), not SQLite,
because the ERP depends on PostgreSQL behaviour that SQLite does not reproduce:
`SELECT … FOR UPDATE` row locking for stock reservations, `NUMERIC` precision
for money, and deferred foreign keys. Testing on a different engine would let
genuine concurrency and rounding bugs through.

```bash
php artisan test                        # everything
php artisan test --filter=Conversion    # one area
```

A module is not finished until its business rules have tests. The ones that
exist today cover unit conversion and its refusals, role enforcement per
policy, audit immutability, account status, and master-data CRUD.

---

## Static analysis

`phpstan.neon` is configured for Larastan, but the package is not currently
installed: `phpstan/phpstan` is published only as a pre-built archive from an
endpoint the current build environment cannot reach. On a normal network:

```bash
composer require --dev larastan/larastan phpstan/phpstan
```

`composer test` then picks it up automatically through the `types:check` script.
