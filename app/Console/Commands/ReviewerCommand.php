<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * A login for looking over a preview copy of the ERP before it goes live:
 * review@hrbd.local with full rights and a fresh password, printed once.
 * Run again, it gives the same account a new password. It will not run on
 * the live site.
 */
class ReviewerCommand extends Command
{
    protected $signature = 'erp:reviewer {--email=review@hrbd.local : The reviewer\'s sign-in}';

    protected $description = 'Create (or reset) a reviewer login on a preview copy of the ERP';

    public function __construct(private readonly AuditLogger $auditLogger)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $live = strtolower((string) config('erp.live_host'));

        if ($live !== '' && strtolower($host) === $live) {
            $this->components->error("This is the live site ({$host}). A reviewer login is for a preview copy only.");

            return self::FAILURE;
        }

        $email = strtolower(trim((string) $this->option('email')));
        $password = Str::password(14, symbols: false);

        $user = User::withTrashed()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $user->name ?: 'Preview Reviewer',
            'password' => $password,
            'status' => UserStatus::Active,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        if ($user->trashed()) {
            $user->restore();
        }

        $user->syncRoles([RoleName::SuperAdmin->value]);

        $this->auditLogger->log(
            action: AuditAction::RolesChanged,
            entity: $user,
            newValues: ['roles' => [RoleName::SuperAdmin->value]],
            description: "Reviewer login {$email} set up on {$host} from the console.",
        );

        $this->components->info('Reviewer login ready. The password is shown once.');
        $this->line('  Site:     '.config('app.url'));
        $this->line("  Email:    {$email}");
        $this->line("  Password: {$password}");

        return self::SUCCESS;
    }
}
