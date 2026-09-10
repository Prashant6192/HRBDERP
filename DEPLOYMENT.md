# Deployment

Getting HRBD ERP onto a real address such as `erp.yourcompany.com`.

**Nothing here is a step you have to perform alone.** The parts needing your
account, your card or your domain are marked **[you]**; the rest can be done
for you.

---

## Choosing where it runs

| Option                    | Suits                                                                                                                   | Trade-off                                          |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------- |
| **Laravel Cloud**         | The straightforward choice. Managed PHP, PostgreSQL, Redis, queue workers and the scheduler, all configured for Laravel | Least to administer; you pay for that              |
| **Laravel Forge + a VPS** | A managed server on DigitalOcean, Hetzner or AWS. Forge provisions and deploys; you own the machine                     | Cheaper at scale; you own patching and monitoring  |
| **Anything else**         | Any host that runs PHP 8.3+, PostgreSQL 16 and Redis behind Nginx                                                       | Everything below still applies, configured by hand |

Either of the first two is a sound choice. Laravel Cloud involves fewer moving
parts; Forge gives more control and costs less as usage grows.

**Never run `php artisan serve` as a production server.** It is a single-process
development tool.

---

## What the server must provide

- PHP 8.3 or later, with `pdo_pgsql`, `mbstring`, `openssl`, `intl`, `zip`, `gd`, `redis`
- PostgreSQL 16
- Redis 7 — cache, queues and locks
- Nginx (or Caddy) with HTTPS
- A supervisor keeping the queue worker alive
- Cron running Laravel's scheduler
- Object storage: Amazon S3 or Cloudflare R2

---

## Before the first deploy **[you]**

1. **Domain.** Point `erp.yourcompany.com` at the server or platform. DNS is
   yours to change.
2. **Hosting account** and payment method.
3. **Object storage bucket**, created **private**, with an access key.
4. **Mail sending** — Postmark, SES or similar, with the sending domain
   verified.
5. **Error monitoring** — Sentry, Bugsnag or Flare, and its DSN.

Everything after that is configuration and can be done for you.

---

## Environment

Copy `.env.example` and set:

```dotenv
APP_NAME="HRBD ERP"
APP_ENV=production
APP_DEBUG=false                 # never true in production
APP_URL=https://erp.yourcompany.com
APP_KEY=                        # php artisan key:generate

# Required. Managed platforms often inject the host and credentials but not
# this, and the application will not start in production without PostgreSQL.
DB_CONNECTION=pgsql
DB_HOST=...
DB_DATABASE=hrbderp
DB_USERNAME=hrbderp
DB_PASSWORD=                    # long and random, not the development default

# Redis for everything that benefits from it
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_ENCRYPT=true
REDIS_HOST=...
REDIS_PASSWORD=...

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=ap-south-1
AWS_BUCKET=hrbderp-documents

MAIL_MAILER=postmark
MAIL_FROM_ADDRESS="erp@yourcompany.com"

ERP_FORMULA_ACCESS_TTL_MINUTES=20
ERP_FORMULA_REQUIRE_PIN=true
```

`APP_KEY` encrypts sessions and cookies. **Back it up somewhere other than the
server.** Losing it invalidates every session and any encrypted column.

---

## Deploy steps

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force        # --force: no prompt on a server

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan queue:restart          # pick up the new code
```

### First deploy only

```bash
php artisan db:seed --force        # units, departments, roles — no demo data in production
```

`DatabaseSeeder` refuses to create demo accounts when `APP_ENV=production`, so
this seeds reference data only.

Then lock the append-only tables:

```bash
php artisan erp:lock-audit-trail
```

It revokes `UPDATE`, `DELETE` and `TRUNCATE` on `audit_logs` and
`formula_access_logs` from the application's own database role, which owns
them. Inserts continue to work; nothing the application runs can alter or
remove a row. `--check` reports the state without changing it. See
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md#immutability-in-three-layers).

### Create the first administrator

There is no public sign-up, so the first account is made from the console:

```bash
php artisan erp:create-admin --name="Your Name" --email="you@yourcompany.com" --password="a long unique password"
```

The password must be at least 12 characters with mixed case, a number and a
symbol. Pass `--role` to assign something other than Super Admin, and
`--promote` to give an existing account a role instead of creating one.

Note that the password will appear in the shell history of whatever ran it.
Change it after signing in, or omit `--password` to be prompted for it where
the console is interactive.

Then sign in and create the rest of the accounts through the interface, where
they are authorised and audited.

---

## Queue worker

Long jobs — imports, exports, PDFs, bulk reconciliation — run on the queue so
nobody waits on a spinning page. The worker must be kept alive by a supervisor.

Laravel Cloud and Forge both configure this. By hand, with Supervisor:

```ini
[program:hrbderp-worker]
command=php /var/www/hrbderp/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/hrbderp-worker.log
stopwaitsecs=3600
```

`--max-time=3600` restarts each worker hourly, which keeps a slow memory leak
from becoming an outage.

---

## Scheduler

One cron entry runs everything Laravel schedules:

```cron
* * * * * cd /var/www/hrbderp && php artisan schedule:run >> /dev/null 2>&1
```

---

## HTTPS

Let's Encrypt through Forge or your platform. Force HTTPS and enable HSTS.

In `AppServiceProvider::boot()`, if your platform does not already do it:

```php
if (app()->isProduction()) {
    URL::forceScheme('https');
}
```

---

## After deploying

- [ ] `https://erp.yourcompany.com` loads over HTTPS with a valid certificate
- [ ] `/up` returns 200
- [ ] Sign-in works; a wrong password is throttled after five attempts
- [ ] `APP_DEBUG=false` — force an error and confirm you get a plain page, not a stack trace
- [ ] Queue worker running (`php artisan queue:monitor`)
- [ ] Scheduler firing (`php artisan schedule:list`)
- [ ] A test file uploads and downloads, and its URL is **not** reachable while signed out
- [ ] `php artisan erp:lock-audit-trail --check` reports both trails locked
- [ ] Error monitoring receiving a deliberately triggered test event
- [ ] Backups running, and **a restore rehearsed** — see [BACKUP_RESTORE.md](BACKUP_RESTORE.md)
- [ ] Laravel Telescope not installed
- [ ] No account has the password `password`

### Setting the factory up

Once signed in as Super Admin, in this order:

1. **Warehouses** — one each of type _Raw Material Store_, _Packaging Store_,
   _Finished Goods Store_, and one ticked _Quarantine store_. Receiving,
   planning and manufacturing find them by type.
2. **Raw materials and packaging** — or let the formulation import create the
   raw materials it meets. Set `minimum_stock` and `reorder_level` on the ones
   that matter: they define Critically low / Low / Moderate.
3. **Products** — net content (e.g. 100 ml) and the packaging-per-unit list on
   each product screen, so plans can count units and raise the packaging
   request.
4. **Formula PIN** — every person with `formula.view` sets their own under
   Formulations → Unlock → _Set or change your PIN_.
5. **Formulations → Import** the formulation workbook. Review the plan it
   shows (materials it will create, sheets it skipped, anything it guessed),
   then import. Activate each version once a chemist has checked it.
6. Book the opening stock in through **Goods Receipts** so every batch has a
   number and a QC decision.

---

## Routine deploys

```bash
php artisan down --render="errors::503"
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --force              # reference data; keeps Roles-screen edits
php artisan erp:sync-permissions         # new abilities reach the roles that should have them
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

`db:seed` and `erp:sync-permissions` are both safe on a live system: a role an
administrator has changed is not reset — it only gains abilities that are new
to the catalogue and belong in its defaults. `erp:sync-permissions --roles` is
the deliberate reset, and is never run by a deploy.

**Take a database backup before any deploy that includes a migration.** A
migration that drops or transforms a column is not reversible by re-running the
old code.

Never run `migrate:fresh`, `db:wipe` or `migrate:refresh` against production.
They drop every table. `DB::prohibitDestructiveCommands` blocks them when
`APP_ENV=production`, which is a safety net, not a licence to try.
