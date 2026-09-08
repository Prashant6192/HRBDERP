<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes audit entries.
 *
 * Everything that records an action goes through here rather than calling
 * AuditLog::create directly, so that the actor, the request context and the
 * entity label are captured the same way every time.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $context
     */
    public function log(
        AuditAction|string $action,
        ?Model $entity = null,
        array $oldValues = [],
        array $newValues = [],
        ?string $description = null,
        array $context = [],
        ?Model $actor = null,
    ): AuditLog {
        $user = $actor ?? Auth::user();

        return AuditLog::create([
            'user_id' => $user?->getKey(),
            'user_name' => $user?->getAttribute('name'),
            'user_email' => $user?->getAttribute('email'),

            'action' => $action instanceof AuditAction ? $action->value : $action,
            'description' => $description,

            'auditable_type' => $entity ? $entity::class : null,
            'auditable_id' => $entity?->getKey(),
            'auditable_label' => $entity ? $this->labelFor($entity) : null,

            'old_values' => $oldValues === [] ? null : $oldValues,
            'new_values' => $newValues === [] ? null : $newValues,

            'ip_address' => $this->clientIp(),
            'user_agent' => $this->userAgent(),
            'session_id' => $this->sessionId(),
            'route' => $this->currentRoute(),

            'context' => $context === [] ? null : $context,
        ]);
    }

    /**
     * A human-readable name for the entity, used so that an audit entry still
     * means something after the row it points at is gone.
     */
    protected function labelFor(Model $entity): ?string
    {
        if (method_exists($entity, 'auditLabel')) {
            return $entity->auditLabel();
        }

        foreach (['name', 'code', 'title', 'email'] as $attribute) {
            $value = $entity->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function clientIp(): ?string
    {
        return $this->hasRequestContext() ? Request::ip() : null;
    }

    protected function userAgent(): ?string
    {
        return $this->hasRequestContext() ? Request::userAgent() : null;
    }

    protected function sessionId(): ?string
    {
        if (! $this->hasRequestContext() || ! Request::hasSession()) {
            return null;
        }

        return Request::session()->getId();
    }

    protected function currentRoute(): ?string
    {
        if (! $this->hasRequestContext()) {
            return null;
        }

        return Request::route()?->getName() ?? Request::path();
    }

    /**
     * Console commands, queued jobs and the scheduler have no meaningful
     * client address or route; recording the synthetic request Laravel
     * provides in those contexts would be worse than recording nothing.
     */
    protected function hasRequestContext(): bool
    {
        return ! app()->runningInConsole();
    }
}
