#!/usr/bin/env bash
#
# HRBD ERP — deployment script.
#
# Run from the application root on the server, as the user that owns the files.
# Laravel Forge runs this as the deploy script; on a hand-built server, run it
# yourself. Laravel Cloud performs the equivalent steps itself and does not need
# this file.
#
# It is safe to run repeatedly. It is NOT safe to run without a database backup
# when the release contains a migration — see BACKUP_RESTORE.md.

set -euo pipefail

echo "==> HRBD ERP deploy — $(date -u +'%Y-%m-%d %H:%M:%S UTC')"

# Refuse to run against an unconfigured environment rather than half-deploying.
[ -f .env ] || { echo "ERROR: no .env file. See DEPLOYMENT.md."; exit 1; }
grep -q '^APP_KEY=base64:' .env || { echo "ERROR: APP_KEY is not set. Run: php artisan key:generate"; exit 1; }

echo "==> Maintenance mode"
php artisan down --retry=15 || true
# Bring the site back even if a later step fails, so a broken deploy does not
# leave the ERP dark.
trap 'php artisan up || true' EXIT

echo "==> PHP dependencies"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "==> Frontend assets"
npm ci
npm run build

echo "==> Database migrations"
php artisan migrate --force

echo "==> Reference data (units, departments, roles, permissions)"
# Idempotent, and skips demo accounts when APP_ENV=production.
php artisan db:seed --force

echo "==> Permission catalogue"
php artisan erp:sync-permissions

echo "==> Caches"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "==> Queue workers pick up the new code"
php artisan queue:restart

echo "==> Storage symlink"
php artisan storage:link || true

php artisan up
trap - EXIT

echo "==> Done. Checking health:"
php artisan about --only=environment

cat <<'REMINDER'

  First deploy only — run these once, then never again:

    1. Lock the audit trail, as the database owner:
         REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM hrbderp;

    2. Create your administrator account:
         php artisan tinker
       then follow DEPLOYMENT.md, "Create the first administrator".

REMINDER
