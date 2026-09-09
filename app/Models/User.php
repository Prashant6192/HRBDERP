<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Audit\Concerns\RecordsAuditTrail;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Department;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * An employee of the company and their access to the ERP.
 *
 * @property int $id
 * @property string|null $employee_code
 * @property string $name
 * @property string $email
 * @property int|null $department_id
 * @property string|null $phone
 * @property string|null $designation
 * @property string|null $avatar_path
 * @property UserStatus $status
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property string|null $formula_pin_hash
 * @property Carbon|null $formula_pin_set_at
 * @property int $formula_pin_failed_attempts
 * @property Carbon|null $formula_pin_locked_until
 * @property bool $must_change_password
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property Carbon|null $two_factor_confirmed_at
 */
#[Fillable([
    'employee_code', 'name', 'email', 'password', 'department_id',
    'phone', 'designation', 'avatar_path', 'status', 'must_change_password',
])]
#[Hidden([
    'password', 'formula_pin_hash',
    'two_factor_secret', 'two_factor_recovery_codes', 'remember_token',
])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles, Notifiable, PasskeyAuthenticatable, RecordsAuditTrail, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Login bookkeeping changes on every sign-in and would otherwise bury the
     * audit trail in noise. Sign-ins are recorded as their own audit action.
     *
     * @var list<string>
     */
    protected array $auditExclude = [
        'last_login_at', 'last_login_ip',
        // PIN bookkeeping is recorded through the formula access trail.
        'formula_pin_hash', 'formula_pin_set_at', 'formula_pin_failed_attempts', 'formula_pin_locked_until',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'formula_pin_set_at' => 'datetime',
            'formula_pin_failed_attempts' => 'integer',
            'formula_pin_locked_until' => 'datetime',
            'must_change_password' => 'boolean',
            'status' => UserStatus::class,
        ];
    }

    public function auditLabel(): string
    {
        return $this->employee_code
            ? "{$this->employee_code} — {$this->name}"
            : $this->name;
    }

    // ---- Relations ---------------------------------------------------------

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    // ---- Account state -----------------------------------------------------

    public function isActive(): bool
    {
        return $this->status->canAuthenticate() && $this->deleted_at === null;
    }

    /**
     * Whether this account bypasses individual permission checks.
     *
     * Kept as a method on the model so that the Gate hook, the Inertia payload
     * and the tests all ask the same question.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleName::SuperAdmin->value);
    }

    /**
     * Withdraw access without destroying the record.
     *
     * Deliberately a method rather than a fillable column: deactivating a
     * colleague ends their session on their next request, and that should be
     * an explicit call, not something a stray form field can do.
     */
    public function deactivate(UserStatus $status = UserStatus::Inactive): void
    {
        if ($status->canAuthenticate()) {
            throw new InvalidArgumentException(
                'deactivate() expects a status that denies authentication.'
            );
        }

        $this->forceFill([
            'status' => $status,
            'deactivated_at' => now(),
        ])->save();
    }

    public function activate(): void
    {
        $this->forceFill([
            'status' => UserStatus::Active,
            'deactivated_at' => null,
        ])->save();
    }

    // ---- Formula security --------------------------------------------------

    /**
     * Set the PIN that guards formulation access.
     *
     * Hashed with the application's configured hasher, exactly as a password
     * is. The plain value is never stored, logged or returned.
     */
    public function setFormulaPin(string $plainPin): void
    {
        $this->forceFill([
            'formula_pin_hash' => Hash::make($plainPin),
            'formula_pin_set_at' => now(),
        ])->save();
    }

    public function hasFormulaPin(): bool
    {
        return $this->formula_pin_hash !== null;
    }

    /**
     * Check a candidate PIN against the stored hash.
     *
     * Returns false rather than throwing when no PIN is set, so that a caller
     * cannot distinguish "wrong PIN" from "no PIN" by the exception type.
     */
    public function verifyFormulaPin(string $plainPin): bool
    {
        if ($this->formula_pin_hash === null) {
            return false;
        }

        return Hash::check($plainPin, $this->formula_pin_hash);
    }

    // ---- Scopes ------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active->value);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%")
                ->orWhere('employee_code', 'ilike', "%{$term}%")
                ->orWhere('designation', 'ilike', "%{$term}%");
        });
    }
}
