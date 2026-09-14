<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Administration\Exceptions\DataResetException;
use App\Domain\Administration\Services\DataResetService;
use Illuminate\Console\Command;

/**
 * The same clearing as the Data screen, for a server console.
 */
class ResetErpDataCommand extends Command
{
    protected $signature = 'erp:reset
        {--scope=* : Which data to clear; repeat the option. Omit to list what there is.}
        {--restart-numbering : Start document numbers again from one}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Clear the operational data a factory entered while testing, keeping people, roles and the audit trail';

    public function handle(DataResetService $reset): int
    {
        $counts = $reset->counts();
        $scopes = array_values(array_filter((array) $this->option('scope')));

        if ($scopes === []) {
            $this->info('Nothing was cleared. Choose one or more scopes with --scope:');
            $this->newLine();
            $this->table(
                ['Scope', 'What it clears', 'Records'],
                collect(DataResetService::SCOPES)->map(fn (array $scope, string $key) => [
                    $key,
                    $scope['label'],
                    number_format($counts[$key] ?? 0),
                ])->values()->all(),
            );

            return self::SUCCESS;
        }

        $resolved = $reset->withDependencies($scopes);
        $added = $reset->addedByDependency($scopes);

        if ($added !== []) {
            $this->warn('Also clearing '.implode(', ', $added).', because what you chose is built on it.');
        }

        $total = array_sum(array_map(fn (string $key) => $counts[$key] ?? 0, $resolved));

        if (! $this->option('force') && ! $this->confirm("Clear {$total} records across ".implode(', ', $resolved).'? This cannot be undone.', false)) {
            $this->info('Nothing was cleared.');

            return self::SUCCESS;
        }

        try {
            $cleared = $reset->reset($scopes, (bool) $this->option('restart-numbering'));
        } catch (DataResetException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($cleared as $table => $rows) {
            $this->line("  {$table}: ".number_format($rows));
        }

        $this->info(number_format(array_sum($cleared)).' records cleared. People, roles and the audit trail are untouched.');

        return self::SUCCESS;
    }
}
