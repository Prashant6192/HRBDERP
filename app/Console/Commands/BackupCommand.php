<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Administration\Exceptions\DataBackupException;
use App\Domain\Administration\Services\DataBackupService;
use Illuminate\Console\Command;

/**
 * The same backup as the Data screen's download, for a server console or
 * a nightly cron.
 */
class BackupCommand extends Command
{
    protected $signature = 'erp:backup {--to= : Where to write the file; a directory gets a dated name, a path is used as given}';

    protected $description = 'Write the whole ERP — every table and every upload — to one zip file';

    public function handle(DataBackupService $backup): int
    {
        $to = $this->option('to');
        $path = match (true) {
            $to === null || $to === '' => getcwd().'/'.$backup->fileName(),
            is_dir($to) => rtrim($to, '/').'/'.$backup->fileName(),
            default => $to,
        };

        try {
            $written = $backup->export($path);
        } catch (DataBackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup written to '.$written.' ('.number_format(filesize($written) / 1024 / 1024, 1).' MB).');

        return self::SUCCESS;
    }
}
