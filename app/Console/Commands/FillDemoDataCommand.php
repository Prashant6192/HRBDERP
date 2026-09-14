<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Administration\Services\DemoFactoryService;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * The same worked example as the Data screen, for a server console.
 */
class FillDemoDataCommand extends Command
{
    protected $signature = 'erp:demo-data {--email= : Who to book it in the name of; the first administrator by default}';

    protected $description = 'Fill the ERP with a worked example: a plant, materials, a recipe, stock, a delivery, a plan and a finished batch';

    public function handle(DemoFactoryService $demo): int
    {
        $actor = $this->option('email')
            ? User::query()->where('email', $this->option('email'))->first()
            : User::query()->oldest('id')->first();

        if ($actor === null) {
            $this->error('No user to book the demo data in the name of. Create an administrator first with erp:create-admin.');

            return self::FAILURE;
        }

        $this->info("Filling in demo data as {$actor->name}…");

        try {
            $made = $demo->fill($actor);
        } catch (Throwable $e) {
            $this->error('The demo data could not be filled in: '.$e->getMessage());
            $this->line('  at '.$e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }

        foreach ($made as $what => $detail) {
            $this->line("  {$what}: {$detail}");
        }

        $this->info('Done. No login accounts were created.');

        return self::SUCCESS;
    }
}
