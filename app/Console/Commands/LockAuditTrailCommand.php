<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Make the append-only tables append-only at the database, not just in code.
 *
 * Hosting panels rarely offer a SQL console, so the REVOKE runs from here
 * over the application's own connection. The application role owns the
 * tables, so it may revoke its own UPDATE, DELETE and TRUNCATE; afterwards
 * no query the application runs — and no bug — can alter or remove a row.
 */
class LockAuditTrailCommand extends Command
{
    private const array TABLES = ['audit_logs', 'formula_access_logs'];

    protected $signature = 'erp:lock-audit-trail {--check : Report the current state without changing anything}';

    protected $description = 'Revoke UPDATE, DELETE and TRUNCATE on the audit and formula access trails';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('This lock is a PostgreSQL privilege; the current connection is not PostgreSQL.');

            return self::FAILURE;
        }

        $allLocked = true;

        foreach (self::TABLES as $table) {
            if (! $this->option('check')) {
                DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON {$table} FROM CURRENT_USER");
            }

            $locked = $this->isLocked($table);
            $allLocked = $allLocked && $locked;

            $this->components->twoColumnDetail($table, $locked ? '<info>locked</info>' : '<comment>writable</comment>');
        }

        if ($this->option('check')) {
            $this->line($allLocked
                ? '  Both trails are append-only at the database.'
                : '  Run without --check to apply the lock.');

            return $allLocked ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info('Rows in these tables can now be added but never changed or removed by the application.');

        return self::SUCCESS;
    }

    private function isLocked(string $table): bool
    {
        $row = DB::selectOne(
            'SELECT has_table_privilege(current_user, ?, \'UPDATE\') AS can_update, '
            .'has_table_privilege(current_user, ?, \'DELETE\') AS can_delete, '
            .'has_table_privilege(current_user, ?, \'TRUNCATE\') AS can_truncate',
            [$table, $table, $table],
        );

        return ! $row->can_update && ! $row->can_delete && ! $row->can_truncate;
    }
}
