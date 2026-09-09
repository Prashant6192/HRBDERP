<?php

declare(strict_types=1);

namespace App\Domain\Access\Models;

use App\Domain\Access\Enums\RoleName;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * The application's own role model.
 *
 * The permission package ships a perfectly good one; this extends it for two
 * reasons.
 *
 * The first is that a model the application owns is one the application can
 * reason about. Route model binding, and the typed frontend route helpers
 * generated from it, can resolve the key type of a class in this codebase but
 * not of one inside a package — which is why /roles/{role} was otherwise typed
 * as taking a string when role ids are integers.
 *
 * The second is that role behaviour has somewhere to live. Whether a role is
 * one of the sixteen the ERP ships with, and what it is for, are questions
 * about a role, and belong here rather than in whichever controller happened
 * to need the answer first.
 *
 * Registered through config/permission.php, so the package uses it everywhere.
 */
class Role extends SpatieRole
{
    /**
     * The built-in role this record corresponds to, if it is one.
     *
     * A role an administrator created themselves returns null.
     */
    public function builtIn(): ?RoleName
    {
        return RoleName::tryFrom($this->name);
    }

    public function isBuiltIn(): bool
    {
        return $this->builtIn() !== null;
    }

    /**
     * What this role is for, where the ERP has an opinion.
     */
    public function description(): ?string
    {
        return $this->builtIn()?->description();
    }

    /**
     * Whether this role bypasses individual permission checks.
     */
    public function isSuperAdmin(): bool
    {
        return $this->builtIn() === RoleName::SuperAdmin;
    }
}
