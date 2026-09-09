<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Formulation\Enums\FormulaAccessAction;
use App\Domain\Formulation\Exceptions\FormulaAccessException;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Models\FormulaAccessLog;
use App\Domain\Formulation\Models\FormulaUnlock;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The second factor in front of every recipe.
 *
 * Holding formula.view gets a user as far as the list of formula names. To
 * see what is in one they must also clear this check, which grants a
 * short-lived unlock bound to their browser session. Every attempt, success
 * or failure, lands in the formula access trail.
 */
class FormulaSecurityService
{
    private const string SESSION_TOKEN_KEY = 'formula_unlock_token';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * How long an unlock lasts, clamped to the configured bounds so that no
     * environment setting can turn it into a permanent one.
     */
    public function ttlMinutes(): int
    {
        $ttl = (int) config('erp.formula_security.access_ttl_minutes', 20);
        $min = (int) config('erp.formula_security.min_ttl_minutes', 5);
        $max = (int) config('erp.formula_security.max_ttl_minutes', 60);

        return max($min, min($max, $ttl));
    }

    public function requiresPin(): bool
    {
        return (bool) config('erp.formula_security.require_pin', true);
    }

    /**
     * Whether the user must set a PIN before they can unlock anything.
     */
    public function needsPinSetup(User $user): bool
    {
        return $this->requiresPin() && ! $user->hasFormulaPin();
    }

    /**
     * The secret that ties an unlock to one browser session.
     *
     * Kept in the session data (so it travels with the session cookie and
     * nowhere else) and stored hashed on the unlock row. Another session of
     * the same account — a phone, a second laptop — has a different token
     * and so must verify for itself.
     */
    public function sessionToken(Session $session): string
    {
        $token = $session->get(self::SESSION_TOKEN_KEY);

        if (! is_string($token) || $token === '') {
            $token = Str::random(40);
            $session->put(self::SESSION_TOKEN_KEY, $token);
        }

        return $token;
    }

    /**
     * The unlock currently in force for this user in this session, if any.
     */
    public function currentUnlock(User $user, ?string $sessionToken): ?FormulaUnlock
    {
        $unlock = FormulaUnlock::query()
            ->where('user_id', $user->getKey())
            ->valid()
            ->latest('unlocked_at')
            ->first();

        if ($unlock === null) {
            return null;
        }

        // An unlock earned in one browser does not travel to another.
        if ($unlock->session_id !== null && ! hash_equals($unlock->session_id, $this->hashToken((string) $sessionToken))) {
            return null;
        }

        return $unlock;
    }

    public function isUnlocked(User $user, ?string $sessionToken): bool
    {
        return $this->currentUnlock($user, $sessionToken) !== null;
    }

    /**
     * Verify the second factor and, if it passes, open the module.
     *
     * @throws FormulaAccessException
     */
    public function unlock(User $user, string $secret, ?string $sessionToken = null, ?string $ip = null, ?string $userAgent = null): FormulaUnlock
    {
        $user->refresh();

        if ($user->formula_pin_locked_until !== null && $user->formula_pin_locked_until->isFuture()) {
            $minutes = max(1, (int) ceil(now()->diffInSeconds($user->formula_pin_locked_until, false) / 60));

            $this->record($user, FormulaAccessAction::UnlockFailed, context: ['reason' => 'locked_out'], ip: $ip, userAgent: $userAgent);

            throw FormulaAccessException::lockedOut($minutes);
        }

        if ($this->requiresPin() && ! $user->hasFormulaPin()) {
            throw FormulaAccessException::noPin();
        }

        $verified = $this->requiresPin()
            ? $user->verifyFormulaPin($secret)
            : Hash::check($secret, (string) $user->getAuthPassword());

        if (! $verified) {
            $this->registerFailure($user, $ip, $userAgent);

            // registerFailure throws when the attempt tipped the user into a
            // lockout; reaching here means they still have attempts left.
            throw FormulaAccessException::invalidPin();
        }

        return DB::transaction(function () use ($user, $sessionToken, $ip, $userAgent): FormulaUnlock {
            $user->forceFill([
                'formula_pin_failed_attempts' => 0,
                'formula_pin_locked_until' => null,
            ])->saveQuietly();

            // One unlock at a time: a fresh verification replaces any other
            // session's grant rather than adding to it.
            $this->revokeAllFor($user);

            $unlock = FormulaUnlock::create([
                'user_id' => $user->getKey(),
                'session_id' => $sessionToken === null ? null : $this->hashToken($sessionToken),
                'unlocked_at' => now(),
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
                'ip_address' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            ]);

            $this->record($user, FormulaAccessAction::Unlocked, context: ['ttl_minutes' => $this->ttlMinutes()], ip: $ip, userAgent: $userAgent);
            $this->audit->log(AuditAction::FormulaUnlocked, $user, description: 'Unlocked formulations', actor: $user);

            return $unlock;
        });
    }

    /**
     * End the unlock early. Also called when a PIN changes or a session ends.
     */
    public function lock(User $user, ?string $ip = null, ?string $userAgent = null): void
    {
        if ($this->revokeAllFor($user) > 0) {
            $this->record($user, FormulaAccessAction::Locked, ip: $ip, userAgent: $userAgent);
        }
    }

    /**
     * Set or replace the user's PIN. The plain value is hashed immediately
     * and never stored, logged or echoed.
     */
    public function setPin(User $user, string $pin, ?string $ip = null, ?string $userAgent = null): void
    {
        DB::transaction(function () use ($user, $pin, $ip, $userAgent): void {
            $user->setFormulaPin($pin);
            $user->forceFill([
                'formula_pin_failed_attempts' => 0,
                'formula_pin_locked_until' => null,
            ])->saveQuietly();

            $this->revokeAllFor($user);

            $this->record($user, FormulaAccessAction::PinSet, ip: $ip, userAgent: $userAgent);
            $this->audit->log(AuditAction::FormulaPinChanged, $user, description: 'Set formula PIN', actor: $user);
        });
    }

    /**
     * Clear a user's PIN so they must set a new one — an administrator's
     * answer to "I forgot it". Ends any unlock they hold.
     */
    public function resetPin(User $user, User $actor): void
    {
        DB::transaction(function () use ($user, $actor): void {
            $user->forceFill([
                'formula_pin_hash' => null,
                'formula_pin_set_at' => null,
                'formula_pin_failed_attempts' => 0,
                'formula_pin_locked_until' => null,
            ])->saveQuietly();

            $this->revokeAllFor($user);

            $this->record($user, FormulaAccessAction::PinSet, context: ['reset_by' => $actor->getKey()]);
            $this->audit->log(AuditAction::FormulaPinChanged, $user, description: "Reset formula PIN for {$user->name}", actor: $actor);
        });
    }

    public function revokeAllFor(User $user): int
    {
        return FormulaUnlock::query()
            ->where('user_id', $user->getKey())
            ->valid()
            ->update(['revoked_at' => now()]);
    }

    /**
     * Write to the formula access trail.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(
        User $user,
        FormulaAccessAction $action,
        ?Formula $formula = null,
        ?FormulaVersion $version = null,
        array $context = [],
        ?string $ip = null,
        ?string $userAgent = null,
    ): FormulaAccessLog {
        return FormulaAccessLog::create([
            'user_id' => $user->getKey(),
            'formula_id' => $formula?->getKey() ?? $version?->formula_id,
            'formula_version_id' => $version?->getKey(),
            'action' => $action,
            'ip_address' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            'context' => $context === [] ? null : $context,
            'occurred_at' => now(),
        ]);
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function registerFailure(User $user, ?string $ip, ?string $userAgent): void
    {
        $attempts = $user->formula_pin_failed_attempts + 1;
        $max = (int) config('erp.formula_security.max_attempts', 5);
        $lockoutMinutes = (int) config('erp.formula_security.lockout_minutes', 15);

        if ($attempts >= $max) {
            $user->forceFill([
                'formula_pin_failed_attempts' => 0,
                'formula_pin_locked_until' => now()->addMinutes($lockoutMinutes),
            ])->saveQuietly();

            $this->record($user, FormulaAccessAction::LockedOut, context: ['lockout_minutes' => $lockoutMinutes], ip: $ip, userAgent: $userAgent);
            $this->audit->log(AuditAction::FormulaUnlockFailed, $user, description: "Formulations locked for {$lockoutMinutes} minutes after {$max} failed attempts", actor: $user);

            throw FormulaAccessException::lockedOut($lockoutMinutes);
        }

        $user->forceFill(['formula_pin_failed_attempts' => $attempts])->saveQuietly();

        $this->record($user, FormulaAccessAction::UnlockFailed, context: ['attempt' => $attempts], ip: $ip, userAgent: $userAgent);
        $this->audit->log(AuditAction::FormulaUnlockFailed, $user, description: 'Failed formula unlock attempt', actor: $user);
    }
}
