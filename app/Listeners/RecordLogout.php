<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Events\Logout;

class RecordLogout
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
        //
    }

    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->auditLogger->log(
            action: AuditAction::LoggedOut,
            entity: $event->user,
            description: 'Signed out.',
            actor: $event->user,
        );
    }
}
