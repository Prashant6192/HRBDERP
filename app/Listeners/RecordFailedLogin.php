<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Events\Failed;

/**
 * Records failed sign-in attempts.
 *
 * A run of these against one account is the clearest early signal of someone
 * trying passwords, so they belong in the same trail an administrator already
 * reads — not only in the web server log.
 */
class RecordFailedLogin
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
        //
    }

    public function handle(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;

        $this->auditLogger->log(
            action: AuditAction::LoginFailed,
            entity: $user,
            description: 'Failed sign-in attempt.',
            // The submitted email is recorded; the submitted password is not,
            // and must never be.
            context: ['email' => $event->credentials['email'] ?? null],
            actor: $user,
        );
    }
}
