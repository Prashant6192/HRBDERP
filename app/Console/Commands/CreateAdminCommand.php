<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Access\Models\Role;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the first administrator, so that a freshly deployed ERP has somebody
 * who can sign in.
 *
 * This exists because the alternative is pasting a block of PHP into a hosting
 * platform's command box, which runs a shell rather than a PHP session and
 * fails on the first bracket. Bootstrapping an ERP should not require knowing
 * that.
 *
 * The account is created directly rather than through the interface for the
 * obvious reason: there is nobody to authorise it yet. Every later account is
 * made under Administration -> Users, where it is authorised and audited.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'erp:create-admin
                            {--name= : The person\'s full name}
                            {--email= : The email address they will sign in with}
                            {--password= : Their password (prompted for if omitted)}
                            {--role=Super Admin : The role to assign}
                            {--promote : Assign the role to an existing account instead of failing}';

    protected $description = 'Create the first administrator account for a new deployment';

    public function __construct(private readonly AuditLogger $auditLogger)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->databaseIsReady()) {
            return self::FAILURE;
        }

        $role = $this->resolveRole();

        if ($role === null) {
            return self::FAILURE;
        }

        // The email is settled first, and the existing-account branch taken
        // before anything else is asked for. Promoting an account that already
        // exists needs no name and no password, and prompting for them would
        // abort in a non-interactive console — which is exactly where this
        // command is usually run.
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email address'))));

        $existing = User::withTrashed()->where('email', $email)->first();

        if ($existing !== null) {
            return $this->handleExisting($existing, $role);
        }

        $name = $this->option('name') ?: $this->ask('Full name');

        $password = $this->resolvePassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', $this->passwordRules()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($name, $email, $password, $role): User {
            // Built and saved once. Creating the record and then verifying the
            // address in a second save would write a spurious "updated" entry
            // to the audit trail, immediately after the "created" one, saying
            // nothing a reader would want.
            $user = new User;

            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ])->save();

            $user->assignRole($role->name);

            // No actor: nobody was signed in. A console action is the system
            // acting, and saying so is more honest than attributing it to the
            // account it happens to be creating.
            $this->auditLogger->log(
                action: AuditAction::RolesChanged,
                entity: $user,
                newValues: ['roles' => [$role->name]],
                description: 'Administrator created from the console during deployment.',
            );

            return $user;
        });

        $this->components->info("Created {$user->email} with the {$role->name} role.");
        $this->warnAboutPasswordExposure();
        $this->line('  Sign in at '.config('app.url').'/login');

        return self::SUCCESS;
    }

    /**
     * A freshly deployed application often has no tables yet. Saying so is more
     * use than an SQL error about a missing relation.
     */
    private function databaseIsReady(): bool
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->components->error('Cannot reach the database: '.$e->getMessage());
            $this->line('  Check DB_CONNECTION, DB_HOST and the credentials for this environment.');

            return false;
        }

        if (! Schema::hasTable('users') || ! Schema::hasTable('roles')) {
            $this->components->error('The database has no schema yet.');
            $this->line('  Run:  php artisan migrate --force && php artisan db:seed --force');

            return false;
        }

        return true;
    }

    private function resolveRole(): ?Role
    {
        $name = (string) $this->option('role');

        $role = Role::where('name', $name)->first();

        if ($role !== null) {
            return $role;
        }

        $this->components->error("The role [{$name}] does not exist.");

        $available = Role::orderBy('name')->pluck('name');

        if ($available->isEmpty()) {
            $this->line('  No roles exist at all. Run:  php artisan db:seed --force');
        } else {
            $this->line('  Available roles: '.$available->implode(', '));
        }

        return null;
    }

    private function handleExisting(User $user, Role $role): int
    {
        if (! $this->option('promote')) {
            $this->components->error("An account already exists for {$user->email}.");
            $this->line('  To give that account this role instead, add --promote');

            return self::FAILURE;
        }

        $before = $user->getRoleNames()->values()->all();

        // Only touch what actually needs changing. Calling restore() and
        // activate() unconditionally writes "restored" and "updated" entries to
        // the audit trail for an account that was never deleted or deactivated,
        // which is noise in the one place that should be worth reading.
        if ($user->trashed()) {
            $user->restore();
        }

        if (! $user->isActive()) {
            $user->activate();
        }

        $user->syncRoles([$role->name]);

        $this->auditLogger->log(
            action: AuditAction::RolesChanged,
            entity: $user,
            oldValues: ['roles' => $before],
            newValues: ['roles' => [$role->name]],
            description: 'Role assigned from the console.',
        );

        $this->components->info("{$user->email} now holds the {$role->name} role.");

        return self::SUCCESS;
    }

    private function resolvePassword(): ?string
    {
        $password = $this->option('password');

        if ($password !== null && $password !== '') {
            return (string) $password;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('No password given, and this session cannot prompt for one.');
            $this->line('  Re-run with --password="..."');

            return null;
        }

        return $this->secret('Password');
    }

    /**
     * Deliberately not Password::default().
     *
     * The production default includes uncompromised(), which calls an external
     * breach-database API. Making the creation of the only account that can
     * sign in depend on an outbound HTTP call — at the one moment the server is
     * least likely to have been configured for it — trades a real risk for a
     * theoretical one.
     */
    private function passwordRules(): Password
    {
        return Password::min(12)->mixedCase()->numbers()->symbols();
    }

    private function warnAboutPasswordExposure(): void
    {
        if ($this->option('password') === null || $this->option('password') === '') {
            return;
        }

        $this->newLine();
        $this->components->warn('The password was passed on the command line.');
        $this->line('  Hosting platforms keep a history of commands that have been run, so');
        $this->line('  treat it as known and change it once you have signed in.');
    }
}
