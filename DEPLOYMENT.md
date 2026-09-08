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

Then apply the audit-trail lock, as the database owner:

```sql
REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM hrbderp;
```

This is what makes the audit trail genuinely append-only rather than
append-only-by-convention. Inserts continue to work. See
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md#immutability-in-three-layers).

### Create the first administrator

```bash
php artisan tinker
```

```php
$user = App\Models\User::create([
    'name' => 'Your Name',
    'email' => 'you@yourcompany.com',
    'password' => 'a long unique password',
    'status' => App\Domain\Identity\Enums\UserStatus::Active,
    'email_verified_at' => now(),
]);

$user->assignRole(App\Domain\Access\Enums\RoleName::SuperAdmin->value);
```

Then sign in and create the rest of the accounts through the interface, where
they are audited.

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
- [ ] `REVOKE` applied — try `UPDATE audit_logs SET action = 'x'` as the app role and confirm it is denied
- [ ] Error monitoring receiving a deliberately triggered test event
- [ ] Backups running, and **a restore rehearsed** — see [BACKUP_RESTORE.md](BACKUP_RESTORE.md)
- [ ] Laravel Telescope not installed
- [ ] No account has the password `password`

---

## Routine deploys

```bash
php artisan down --render="errors::503"
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

**Take a database backup before any deploy that includes a migration.** A
migration that drops or transforms a column is not reversible by re-running the
old code.

Never run `migrate:fresh`, `db:wipe` or `migrate:refresh` against production.
They drop every table. `DB::prohibitDestructiveCommands` blocks them when
`APP_ENV=production`, which is a safety net, not a licence to try.
