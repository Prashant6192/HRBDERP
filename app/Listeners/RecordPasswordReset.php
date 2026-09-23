<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Every password reset lands in the audit trail, with the address and
 * browser it was done from, like a sign-in does.
 */
class RecordPasswordReset
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
        //
    }

    public function handle(PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->auditLogger->log(
            action: AuditAction::PasswordReset,
            entity: $event->user,
            description: 'Reset their password from an emailed link.',
            actor: $event->user,
        );
    }
}
