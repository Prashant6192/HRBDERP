# Backup and restore

A backup nobody has restored is a hope, not a backup. The most important part
of this document is [rehearsing the restore](#rehearsing-the-restore).

---

## What has to be backed up

| What                    | Where it lives      | Lost if not backed up                                               |
| ----------------------- | ------------------- | ------------------------------------------------------------------- |
| **PostgreSQL database** | The database server | Everything: the ledger, batches, formulations and their access trail, plans, requests, orders, the audit trail |
| **Uploaded files**      | S3 or R2            | Quotations, invoices, certificates of analysis, QC reports, artwork |
| **Nothing under `storage/app/private/formula-imports`** | The server | Workbooks waiting for an import to be confirmed — deleted when it is; a restore simply starts the import again |
| **`APP_KEY`**           | `.env`              | Every session, and anything encrypted                               |
| **`.env`**              | The server          | Configuration and credentials                                       |

The application code is in Git and does not need backing up. Everything above
does.

---

## Database

### What good looks like

| Setting                | Recommendation                                 |
| ---------------------- | ---------------------------------------------- |
| Full backup            | Daily, outside working hours                   |
| Retention              | 30 days daily, 12 months monthly               |
| Point-in-time recovery | On, if the platform offers it                  |
| Location               | A different provider or region from the server |
| Encryption             | At rest, always                                |

If the database is managed — Laravel Cloud, RDS, DigitalOcean — turn on
automated backups and point-in-time recovery and confirm the retention window.
That is the whole job.

### By hand

```bash
# Backup
pg_dump -Fc -h HOST -U hrbderp hrbderp > hrbderp-$(date +%F-%H%M).dump

# Compress and encrypt before it leaves the machine
gpg --symmetric --cipher-algo AES256 hrbderp-2026-09-08-0200.dump

# Ship it off the server
aws s3 cp hrbderp-2026-09-08-0200.dump.gpg s3://hrbderp-backups/db/
```

`-Fc` is the custom format: compressed, and restorable selectively.

A nightly cron:

```cron
0 2 * * * /usr/local/bin/hrbderp-backup.sh >> /var/log/hrbderp-backup.log 2>&1
```

**A backup job that fails silently is worse than none**, because it removes the
worry without removing the risk. Have the script exit non-zero on failure and
alert you — a dead-man's-switch service, or a line in your error monitor.

---

## Files

If documents are in S3 or R2, enable **versioning** and a **lifecycle rule**, so
an overwritten or deleted file can be recovered. Cross-region replication if the
data warrants it.

For local storage, back up `storage/app` alongside the database, at the same
moment, so the two agree. Batch stickers and material-request PDFs are
generated on request from the database and are not stored.

---

## `.env` and `APP_KEY`

Keep a copy of `.env` in a password manager or secrets vault. It contains
credentials, so it does not belong in Git or in the same bucket as the backups.

`APP_KEY` deserves its own entry. Restoring a database without it leaves you
with rows you cannot decrypt.

---

## Restoring

### The database

```bash
# 1. Stop traffic
php artisan down

# 2. Stop the queue, so no job writes during the restore
php artisan queue:pause    # or stop the supervisor program

# 3. Restore into a NEW database first, never over the live one
createdb -O hrbderp hrbderp_restore
pg_restore -h HOST -U hrbderp -d hrbderp_restore --clean --if-exists hrbderp-2026-09-08.dump

# 4. Check it before switching. Are the counts what you expect?
psql -h HOST -U hrbderp -d hrbderp_restore -c "
  SELECT 'users' t, count(*) FROM users
  UNION ALL SELECT 'items', count(*) FROM items
  UNION ALL SELECT 'audit_logs', count(*) FROM audit_logs;"

# 5. Only then point DB_DATABASE at the restored database and bring it up
php artisan config:cache
php artisan up
```

**Restore into a new database, not over the live one.** If the backup turns out
to be older or emptier than you thought, you still have the original. Renaming
is instant; undoing a bad `pg_restore --clean` over live data is not.

### After any restore

Run `php artisan erp:lock-audit-trail` again, because a restore creates the
tables afresh with their privileges intact. Stock balances are
restored with everything else; if there is any doubt that they agree with the
ledger, `StockBalanceService::rebuild()` recomputes every position from it.

```sql
REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM hrbderp;
```

Grants do not always survive a restore. Re-apply this, or the audit trail is
append-only in name only.

Then check:

- [ ] Sign-in works
- [ ] Stock balances match what you expect for a known item
- [ ] The audit trail's most recent entry is close to the backup time
- [ ] Uploaded files still open — the database and file storage must be from the
      same point in time, or a record will point at a file that is not there

---

## Rehearsing the restore

Do this **before** you need it, and roughly every quarter.

1. Take last night's backup.
2. Restore it to a scratch database on a staging machine.
3. Point a copy of the application at it.
4. Sign in. Open a product. Check a stock figure against production.
5. Write down how long it took.

That last step is the point. "We have backups" is not an answer to "how long
until we are running again?" — the rehearsal is what turns it into one.

---

## Before a risky change

Take a manual backup before:

- Any deploy that includes a migration
- Bulk imports
- Bulk price or stock updates
- Any first run of a new module against real data

```bash
pg_dump -Fc -h HOST -U hrbderp hrbderp > pre-change-$(date +%F-%H%M).dump
```

Thirty seconds beforehand, against an afternoon of reconstruction afterwards.

---

## Commands that destroy data

These drop every table:

```
php artisan migrate:fresh
php artisan migrate:refresh
php artisan db:wipe
```

`DB::prohibitDestructiveCommands` blocks them when `APP_ENV=production`. They
are not blocked in development, and a development database with real data
imported into it is just as gone. Back up first, or work on a copy.
