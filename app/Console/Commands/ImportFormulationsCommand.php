<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Formulation\DTOs\ImportOptions;
use App\Domain\Formulation\Services\FormulaImportService;
use App\Domain\Formulation\Services\FormulationSheetParser;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Import formulations from a workbook on disk.
 *
 * The screen under Formulations → Import does the same thing with a
 * preview; this is for operators with shell access, and for seeding a
 * fresh environment from the company's master sheet.
 */
class ImportFormulationsCommand extends Command
{
    protected $signature = 'erp:import-formulations
        {path : Path to the .xlsx workbook}
        {--user= : Email of the user to record as the importer}
        {--dry-run : Show what would happen without writing anything}
        {--no-water-qs : Do not add Purified Water as QS where a sheet lists no filler}
        {--activate : Activate each imported version that accounts for the whole batch}';

    protected $description = 'Import product formulations from an Excel workbook';

    public function handle(FormulationSheetParser $parser, FormulaImportService $importer): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("No file at {$path}.");

            return self::FAILURE;
        }

        $userId = null;

        if ($email = $this->option('user')) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $this->error("No user with email {$email}.");

                return self::FAILURE;
            }

            $userId = $user->id;
        }

        $options = new ImportOptions(
            assumeWaterQs: ! $this->option('no-water-qs'),
            activate: (bool) $this->option('activate'),
        );

        if ($options->activate && $userId === null) {
            $this->error('--activate needs --user so the approval is attributed to someone.');

            return self::FAILURE;
        }

        $workbook = $parser->parseFile($path);
        $plan = $importer->plan($workbook, $options);

        $this->components->info(sprintf(
            '%d sheet(s) read, %d skipped as empty.',
            count($workbook->formulas),
            count($workbook->skippedSheets),
        ));

        $this->table(
            ['Sheet', 'Formula', 'Basis', 'Lines', 'Total %', 'QS', 'New materials', 'Action'],
            array_map(static fn (array $f): array => [
                $f['sheet'],
                $f['name'],
                "{$f['batch_size']} {$f['batch_uom']}",
                count($f['lines']),
                $f['total_percentage'],
                $f['has_qs'] ? 'yes' : 'no',
                count(array_filter($f['lines'], static fn (array $l): bool => $l['action'] === 'create')),
                $f['action'],
            ], $plan->formulas),
        );

        foreach ($plan->formulas as $f) {
            foreach ($f['warnings'] as $warning) {
                $this->line("  <comment>{$f['name']}:</comment> {$warning}");
            }

            foreach ($f['lines'] as $line) {
                foreach ($line['warnings'] as $warning) {
                    $this->line("  <comment>{$f['name']} › {$line['name']}:</comment> {$warning}");
                }
            }
        }

        if ($this->option('dry-run')) {
            $this->components->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $result = $importer->import($workbook, $options, $userId);

        $this->components->info(sprintf(
            'Imported: %d formula(s) created, %d new version(s), %d skipped, %d raw material(s) added.',
            count($result->created),
            count($result->versions),
            count($result->skipped),
            count($result->materialsCreated),
        ));

        foreach ($result->created as $row) {
            $this->line("  <info>created</info> {$row['code']} {$row['name']} v{$row['version']}".($row['activated'] ? ' (active)' : ''));
        }

        foreach ($result->versions as $row) {
            $this->line("  <info>revised</info> {$row['code']} {$row['name']} v{$row['version']}".($row['activated'] ? ' (active)' : ''));
        }

        foreach ($result->skipped as $row) {
            $this->line("  <comment>skipped</comment> {$row['name']} — {$row['reason']}");
        }

        return self::SUCCESS;
    }
}
