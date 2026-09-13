<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Services;

use App\Domain\Approvals\Models\ApprovalAction;

/**
 * A digital signature over an approval action.
 *
 * The hash binds who acted, what they did, when, on which step of which
 * approval, over which record — keyed with the application's signature
 * key, so a row cannot be forged or altered without the key, and any
 * later tampering with the row is detectable by verifying it.
 */
final class ApprovalSignature
{
    public static function sign(ApprovalAction $action, string $approvableType, int|string $approvableId): string
    {
        return hash_hmac('sha256', self::payload($action, $approvableType, $approvableId), (string) config('approvals.signature_key'));
    }

    public static function verify(ApprovalAction $action, string $approvableType, int|string $approvableId): bool
    {
        return $action->signature_hash !== null
            && hash_equals($action->signature_hash, self::sign($action, $approvableType, $approvableId));
    }

    private static function payload(ApprovalAction $action, string $approvableType, int|string $approvableId): string
    {
        return implode('|', [
            (string) $action->approval_id,
            (string) ($action->approval_step_id ?? ''),
            (string) $action->user_id,
            $action->action instanceof \BackedEnum ? $action->action->value : (string) $action->action,
            (string) ($action->comment ?? ''),
            $action->acted_at?->toIso8601String() ?? '',
            $approvableType,
            (string) $approvableId,
        ]);
    }
}
