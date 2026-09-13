<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Intelligence\Services\EscalationService;
use Illuminate\Console\Command;

/**
 * Runs the escalation ladder: anything pending too long is raised to the
 * people responsible, once per level. Scheduled hourly; safe to run by hand.
 */
class EscalateCommand extends Command
{
    protected $signature = 'erp:escalate';

    protected $description = 'Escalate exceptions that have stood too long to the roles responsible.';

    public function handle(EscalationService $escalation): int
    {
        $result = $escalation->run();

        $this->info("Raised {$result['raised']}, resolved {$result['resolved']}, {$result['standing']} standing.");

        return self::SUCCESS;
    }
}
