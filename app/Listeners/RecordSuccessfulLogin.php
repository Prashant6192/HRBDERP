<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Request;

/**
 * Stamps the account with when and where it was last used, and records the
 * sign-in in the audit trail.
 */
class RecordSuccessfulLogin
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
        //
    }

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        // forceFill/saveQuietly: this is bookkeeping, not a change the user
        // made, and it should not raise a model 'updated' audit entry on top
        // of the sign-in entry written below.
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => Request::ip(),
        ])->saveQuietly();

        $this->auditLogger->log(
            action: AuditAction::LoggedIn,
            entity: $user,
            description: 'Signed in.',
            context: ['guard' => $event->guard],
            actor: $user,
        );
    }
}
