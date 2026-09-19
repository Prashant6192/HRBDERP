<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Administration\Exceptions\DataBackupException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Throwable;
use ZipArchive;

/**
 * The whole ERP in one file, and the whole ERP back from it.
 *
 * A backup is a zip: one JSON file per table, every uploaded file, and a
 * manifest saying what the system looked like when it was taken. A
 * restore puts every table back to what the file holds — people, roles,
 * stores, materials, stock, batches, everything — and the files with
 * them. The audit trail is never deleted, because the database will not
 * allow it: entries in the backup that are missing are added, and what
 * is already there stays.
 *
 * The restore is one transaction. If anything fails, nothing changed.
 * Before it starts, a copy of what is there now is kept on the server.
 */
class DataBackupService
{
    public const int FORMAT = 1;

    public const string APP = 'hrbderp';

    /** Tables that are the framework's housekeeping, not the company's data. */
    public const array SKIP = ['cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations', 'password_reset_tokens', 'sessions'];

    /** Locked at the database: rows are only ever added. */
    public const array APPEND_ONLY = ['audit_logs', 'formula_access_logs'];

    public const string DISK = 'local';

    /** Where the copy taken before a restore is kept, on the same disk. */
    public const string COPIES_DIR = 'backups';

    /** Uploaded folders that are not the company's records. */
    private const array SKIP_FOLDERS = ['backups', 'formula-imports', 'livewire-tmp'];

    private const int CHUNK = 500;

    // ---- Export ---------------------------------------------------------

    /**
     * Write the backup and return its path. With no destination it goes to
     * a temporary file the caller sends or moves.
     */
    public function export(?string $to = null): string
    {
        $path = $to ?? tempnam(sys_get_temp_dir(), 'hrbd-backup-');

        if ($path === false) {
            throw new DataBackupException('No temporary space to write the backup.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new DataBackupException('The backup file could not be created.');
        }

        $temp = [];
        $tables = [];

        try {
            foreach ($this->tables() as $table) {
                $file = tempnam(sys_get_temp_dir(), 'hrbd-table-');
                $temp[] = $file;
                $tables[$table] = $this->dumpTable($table, $file);
                $zip->addFile($file, "tables/{$table}.json");
            }

            $files = 0;
            $disk = Storage::disk(self::DISK);

            foreach ($disk->allFiles() as $relative) {
                $top = explode('/', $relative, 2)[0];

                if (in_array($top, self::SKIP_FOLDERS, true) || str_starts_with($relative, '.')) {
                    continue;
                }

                $zip->addFile($disk->path($relative), 'storage/'.$relative);
                $files++;
            }

            $zip->addFromString('manifest.json', (string) json_encode([
                'app' => self::APP,
                'format' => self::FORMAT,
                'company' => (string) config('erp.company.name'),
                'app_url' => (string) config('app.url'),
                'created_at' => now()->toIso8601String(),
                'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
                'tables' => $tables,
                'files' => $files,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $zip->close();
        } finally {
            foreach ($temp as $file) {
                @unlink($file);
            }
        }

        return $path;
    }

    /**
     * The name a downloaded backup carries.
     */
    public function fileName(): string
    {
        return 'hrbd-erp-backup-'.now()->format('Y-m-d-Hi').'.zip';
    }

    // ---- Inspect --------------------------------------------------------

    /**
     * What a backup file holds, and whether this system can take it.
     *
     * @return array{company: string, created_at: string, tables: array<string, int>, rows: int, files: int, unknown_tables: list<string>, newer_migrations: list<string>, missing_migrations: list<string>}
     */
    public function inspect(string $path): array
    {
        $zip = $this->openForReading($path);

        try {
            $manifest = $this->manifest($zip);
        } finally {
            $zip->close();
        }

        $known = $this->tables();
        $current = DB::table('migrations')->pluck('migration')->all();
        $inBackup = (array) ($manifest['migrations'] ?? []);

        return [
            'company' => (string) ($manifest['company'] ?? ''),
            'created_at' => (string) ($manifest['created_at'] ?? ''),
            'tables' => $manifest['tables'],
            'rows' => array_sum($manifest['tables']),
            'files' => (int) ($manifest['files'] ?? 0),
            'unknown_tables' => array_values(array_diff(array_keys($manifest['tables']), $known)),
            // Migrations the backup has that this system has not run: the
            // backup is from a newer build and may hold columns we lack.
            'newer_migrations' => array_values(array_diff($inBackup, $current)),
            // Migrations this system has run since the backup was taken.
            'missing_migrations' => array_values(array_diff($current, $inBackup)),
        ];
    }

    // ---- Restore --------------------------------------------------------

    /**
     * Put everything back from the file.
     *
     * @param  int|null  $keepUserId  The person restoring: never deleted, so they stay signed in.
     * @return array{tables: int, rows: int, deleted: int, files: int, copy: string|null, warnings: list<string>}
     */
    public function restore(string $path, ?int $keepUserId = null, bool $keepCopy = true): array
    {
        $inspection = $this->inspect($path);

        if ($inspection['newer_migrations'] !== []) {
            throw new DataBackupException('This backup was taken from a newer version of the ERP ('.count($inspection['newer_migrations']).' database change'.(count($inspection['newer_migrations']) === 1 ? '' : 's').' this system has not had). Update the ERP first, then restore.');
        }

        $warnings = [];

        foreach ($inspection['unknown_tables'] as $table) {
            $warnings[] = "The backup holds a table this system does not have ({$table}); it was skipped.";
        }

        $copy = null;

        if ($keepCopy) {
            $copy = self::COPIES_DIR.'/before-restore-'.now()->format('Y-m-d-His').'.zip';
            Storage::disk(self::DISK)->makeDirectory(self::COPIES_DIR);
            $this->export(Storage::disk(self::DISK)->path($copy));
        }

        $zip = $this->openForReading($path);

        try {
            $manifest = $this->manifest($zip);
            $tables = array_values(array_intersect(array_keys($manifest['tables']), $this->tables()));

            $result = DB::transaction(function () use ($zip, $tables, $keepUserId, &$warnings): array {
                [$order, $deferred] = $this->insertOrder($tables);
                $rows = 0;
                $deleted = 0;
                $keep = [];
                $patches = [];

                foreach ($order as $table) {
                    $data = $this->readTable($zip, $table);
                    $columns = Schema::getColumnListing($table);
                    $dropped = [];
                    $hasId = in_array('id', $columns, true);
                    $deferredColumns = $deferred[$table] ?? [];
                    $ids = [];
                    $chunk = [];

                    if (! $hasId && ! in_array($table, self::APPEND_ONLY, true)) {
                        // No key to match on: the table is replaced whole.
                        DB::table($table)->delete();
                    }

                    foreach ($data as $row) {
                        $row = (array) $row;

                        foreach (array_keys($row) as $column) {
                            if (! in_array($column, $columns, true)) {
                                $dropped[$column] = true;
                                unset($row[$column]);
                            }
                        }

                        foreach ($deferredColumns as $column) {
                            if (array_key_exists($column, $row) && $row[$column] !== null && $hasId) {
                                $patches[] = [$table, $row['id'], $column, $row[$column]];
                                $row[$column] = null;
                            }
                        }

                        if ($hasId) {
                            $ids[] = $row['id'];
                        }

                        $chunk[] = $row;

                        if (count($chunk) >= self::CHUNK) {
                            $rows += $this->writeChunk($table, $chunk, $columns, $hasId);
                            $chunk = [];
                        }
                    }

                    if ($chunk !== []) {
                        $rows += $this->writeChunk($table, $chunk, $columns, $hasId);
                    }

                    if ($dropped !== []) {
                        $warnings[] = "{$table}: the backup had columns this system no longer has (".implode(', ', array_keys($dropped)).'); they were left out.';
                    }

                    if ($hasId) {
                        $keep[$table] = $ids;
                    }
                }

                foreach ($patches as [$table, $id, $column, $value]) {
                    DB::table($table)->where('id', $id)->update([$column => $value]);
                }

                // What is here now but not in the backup goes, children first.
                DB::statement('CREATE TEMP TABLE restore_keep (id bigint PRIMARY KEY) ON COMMIT DROP');

                foreach (array_reverse($order) as $table) {
                    if (! isset($keep[$table]) || in_array($table, self::APPEND_ONLY, true)) {
                        continue;
                    }

                    DB::table('restore_keep')->delete();

                    foreach (array_chunk($keep[$table], 2000) as $ids) {
                        DB::table('restore_keep')->insert(array_map(fn ($id) => ['id' => $id], $ids));
                    }

                    if ($table === 'users' && $keepUserId !== null) {
                        DB::table('restore_keep')->insertOrIgnore(['id' => $keepUserId]);
                    }

                    try {
                        // A savepoint: a table that cannot be trimmed (a person
                        // the audit trail still names) is kept, not fatal.
                        $deleted += DB::transaction(fn () => DB::table($table)->whereNotIn('id', DB::table('restore_keep')->select('id'))->delete());
                    } catch (Throwable $e) {
                        $warnings[] = "{$table}: rows that are not in the backup could not be removed and were kept (".$this->reason($e).').';
                    }
                }

                // New records number on from the restored ones.
                foreach ($order as $table) {
                    $sequence = isset($keep[$table]) ? DB::scalar("SELECT pg_get_serial_sequence(?, 'id')", [$table]) : null;

                    if (is_string($sequence) && $sequence !== '') {
                        DB::statement("SELECT setval(?, COALESCE((SELECT MAX(id) FROM \"{$table}\"), 1), (SELECT MAX(id) FROM \"{$table}\") IS NOT NULL)", [$sequence]);
                    }
                }

                return ['tables' => count($order), 'rows' => $rows, 'deleted' => $deleted];
            });

            $files = $this->restoreFiles($zip);
        } finally {
            $zip->close();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [...$result, 'files' => $files, 'copy' => $copy, 'warnings' => $warnings];
    }

    // ---- Internals ------------------------------------------------------

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_values(array_filter(
            array_map(fn (array $t) => $t['name'], Schema::getTables()),
            fn (string $name) => ! in_array($name, self::SKIP, true) && ! str_starts_with($name, 'restore_'),
        ));
    }

    private function dumpTable(string $table, string $file): int
    {
        $handle = fopen($file, 'w');

        if ($handle === false) {
            throw new DataBackupException("Could not write the rows of {$table}.");
        }

        fwrite($handle, '[');
        $count = 0;
        $query = DB::table($table);

        if (Schema::hasColumn($table, 'id')) {
            $query->orderBy('id');
        }

        foreach ($query->cursor() as $row) {
            fwrite($handle, ($count === 0 ? '' : ",\n").json_encode($row, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            $count++;
        }

        fwrite($handle, ']');
        fclose($handle);

        return $count;
    }

    private function openForReading(string $path): ZipArchive
    {
        $zip = new ZipArchive;

        if (! is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new DataBackupException('The file could not be opened as a backup. Upload the .zip the ERP produced, unchanged.');
        }

        return $zip;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('manifest.json');
        $manifest = $raw === false ? null : json_decode($raw, true);

        if (! is_array($manifest) || ($manifest['app'] ?? null) !== self::APP || ! is_array($manifest['tables'] ?? null)) {
            throw new DataBackupException('This is not an ERP backup: the manifest is missing or belongs to something else.');
        }

        if ((int) ($manifest['format'] ?? 0) > self::FORMAT) {
            throw new DataBackupException('This backup was written in a newer format than this system can read. Update the ERP first.');
        }

        return $manifest;
    }

    /**
     * @return list<object>
     */
    private function readTable(ZipArchive $zip, string $table): array
    {
        $raw = $zip->getFromName("tables/{$table}.json");

        if ($raw === false) {
            return [];
        }

        $rows = json_decode($raw);

        if (! is_array($rows)) {
            throw new DataBackupException("The rows of {$table} in the backup could not be read.");
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     */
    private function writeChunk(string $table, array $rows, array $columns, bool $hasId): int
    {
        if ($rows === []) {
            return 0;
        }

        if (in_array($table, self::APPEND_ONLY, true) || ! $hasId) {
            return DB::table($table)->insertOrIgnore($rows);
        }

        $update = array_values(array_diff($columns, ['id']));

        if ($update === []) {
            return DB::table($table)->insertOrIgnore($rows);
        }

        // Every row carries every column, so one statement fits all.
        $rows = array_map(fn (array $row) => array_replace(array_fill_keys($columns, null), $row), $rows);

        DB::table($table)->upsert($rows, ['id'], $update);

        return count($rows);
    }

    /**
     * Parents before children, from the database's own foreign keys. A
     * cycle (a store's manager who is a person who is assigned to the
     * store) is broken by leaving the nullable reference out on insert
     * and filling it in afterwards.
     *
     * @param  list<string>  $tables
     * @return array{0: list<string>, 1: array<string, list<string>>}
     */
    private function insertOrder(array $tables): array
    {
        $edges = [];
        $columnsOf = [];

        foreach (DB::select(<<<'SQL'
            SELECT tc.table_name AS child, kcu.column_name AS column, ccu.table_name AS parent, c.is_nullable
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
            JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
            JOIN information_schema.columns c ON c.table_schema = tc.table_schema AND c.table_name = tc.table_name AND c.column_name = kcu.column_name
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = current_schema()
        SQL) as $fk) {
            if ($fk->child === $fk->parent || ! in_array($fk->child, $tables, true) || ! in_array($fk->parent, $tables, true)) {
                continue;
            }

            $edges[$fk->child][$fk->parent] = true;
            $columnsOf[$fk->child][$fk->parent][] = ['column' => $fk->column, 'nullable' => $fk->is_nullable === 'YES'];
        }

        $remaining = array_fill_keys($tables, true);
        $order = [];
        $deferred = [];

        while ($remaining !== []) {
            $progress = false;

            foreach (array_keys($remaining) as $table) {
                $parents = array_keys(array_filter($edges[$table] ?? [], fn ($v, $p) => isset($remaining[$p]), ARRAY_FILTER_USE_BOTH));

                if ($parents === []) {
                    $order[] = $table;
                    unset($remaining[$table]);
                    $progress = true;
                }
            }

            if ($progress) {
                continue;
            }

            // Everything left is in a cycle: break it at the table whose
            // remaining references are all nullable.
            foreach (array_keys($remaining) as $table) {
                $parents = array_keys(array_filter($edges[$table] ?? [], fn ($v, $p) => isset($remaining[$p]), ARRAY_FILTER_USE_BOTH));
                $columns = [];
                $breakable = true;

                foreach ($parents as $parent) {
                    foreach ($columnsOf[$table][$parent] as $ref) {
                        if (! $ref['nullable']) {
                            $breakable = false;
                        }

                        $columns[] = $ref['column'];
                    }
                }

                if ($breakable) {
                    $deferred[$table] = array_values(array_unique($columns));
                    $order[] = $table;
                    unset($remaining[$table]);
                    $progress = true;
                    break;
                }
            }

            if (! $progress) {
                throw new DataBackupException('The tables '.implode(', ', array_keys($remaining)).' refer to each other in a way the restore cannot order.');
            }
        }

        return [$order, $deferred];
    }

    private function restoreFiles(ZipArchive $zip): int
    {
        $disk = Storage::disk(self::DISK);
        $files = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (! str_starts_with($name, 'storage/') || str_ends_with($name, '/')) {
                continue;
            }

            $relative = substr($name, strlen('storage/'));

            if (str_contains($relative, '..')) {
                continue;
            }

            $stream = $zip->getStream($name);

            if ($stream === false) {
                continue;
            }

            $disk->writeStream($relative, $stream);
            $files++;
        }

        return $files;
    }

    private function reason(Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'permission denied')) {
            return 'the audit trail refers to them';
        }

        return mb_substr(trim((string) strtok($message, "\n")), 0, 160);
    }
}
