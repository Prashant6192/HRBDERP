<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Administration\Exceptions\DataBackupException;
use App\Domain\Administration\Services\DataBackupService;
use Illuminate\Console\Command;

/**
 * The same restore as the Data screen's upload, for a server console.
 */
class RestoreCommand extends Command
{
    protected $signature = 'erp:restore {file : The backup zip}
        {--no-copy : Do not keep a copy of the current data first}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Put every table and every upload back from a backup made by erp:backup or the Data screen';

    public function handle(DataBackupService $backup): int
    {
        $file = (string) $this->argument('file');

        try {
            $inspection = $backup->inspect($file);
        } catch (DataBackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Backup of', 'Taken', 'Tables', 'Records', 'Files'], [[
            $inspection['company'], $inspection['created_at'], count($inspection['tables']), number_format($inspection['rows']), number_format($inspection['files']),
        ]]);

        if ($inspection['newer_migrations'] !== []) {
            $this->error('The backup is from a newer build of the ERP. Deploy the latest build, then restore.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Replace everything in this system with the backup? The audit trail keeps what it has.', false)) {
            $this->info('Nothing was changed.');

            return self::SUCCESS;
        }

        try {
            $report = $backup->restore($file, null, ! $this->option('no-copy'));
        } catch (DataBackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(number_format($report['rows']).' records across '.$report['tables'].' tables and '.number_format($report['files']).' files restored; '.number_format($report['deleted']).' newer records removed.');

        if ($report['copy'] !== null) {
            $this->line('A copy of what was there before is kept at storage/app/private/'.$report['copy']);
        }

        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
