<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The command that gets the first person into a new deployment.
 *
 * Every one of these runs the command non-interactively, because that is the
 * only way it is ever used in anger: a hosting platform's command box has no
 * terminal to prompt at, and a prompt there is not a question but a failure.
 */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function it_creates_a_working_super_admin(): void
    {
        $this->artisan('erp:create-admin', [
            '--name' => 'Prashant',
            '--email' => 'owner@hrbd.local',
            '--password' => 'Str0ng!Passphrase22',
        ])->assertSuccessful();

        $user = User::where('email', 'owner@hrbd.local')->sole();

        $this->assertTrue($user->hasRole(RoleName::SuperAdmin->value));
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Str0ng!Passphrase22', $user->password));

        // The whole point: this account can actually do something.
        $this->assertTrue($user->can('formula.approve'));
    }

    #[Test]
    public function the_created_account_can_sign_in(): void
    {
        $this->artisan('erp:create-admin', [
            '--name' => 'Prashant',
            '--email' => 'owner@hrbd.local',
            '--password' => 'Str0ng!Passphrase22',
        ])->assertSuccessful();

        $this->post(route('login.store'), [
            'email' => 'owner@hrbd.local',
            'password' => 'Str0ng!Passphrase22',
        ]);

        $this->assertAuthenticated();
    }

    #[Test]
    public function the_password_is_never_echoed(): void
    {
        $this->artisan('erp:create-admin', [
            '--name' => 'Prashant',
            '--email' => 'owner@hrbd.local',
            '--password' => 'Str0ng!Passphrase22',
        ])->doesntExpectOutputToContain('Str0ng!Passphrase22');
    }

    #[Test]
    public function a_weak_password_is_refused_and_no_account_is_created(): void
    {
        $this->artisan('erp:create-admin', [
            '--name' => 'Prashant',
            '--email' => 'owner@hrbd.local',
            '--password' => 'Kasvi@66',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'owner@hrbd.local']);
    }

    #[Test]
    public function an_unknown_role_is_refused(): void
    {
        $this->artisan('erp:create-admin', [
            '--name' => 'Prashant',
            '--email' => 'owner@hrbd.local',
            '--password' => 'Str0ng!Passphrase22',
            '--role' => 'Wizard',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'owner@hrbd.local']);
    }

    #[Test]
    public function any_role_can_be_assigned_not_only_super_admin(): void
    {
        $this->artisan('erp:create-admin', [
            '--name' => 'Imran',
            '--email' => 'warehouse@hrbd.local',
            '--password' => 'Str0ng!Passphrase22',
            '--role' => RoleName::WarehouseManager->value,
        ])->assertSuccessful();

        $user = User::where('email', 'warehouse@hrbd.local')->sole();

        $this->assertTrue($user->hasRole(RoleName::WarehouseManager->value));
        $this->assertFalse($user->can('formula.view'));
    }

    #[Test]
    public function a_duplicate_email_is_refused_rather_than_overwriting(): void
    {
        User::factory()->create(['email' => 'owner@hrbd.local', 'name' => 'The Original']);

        $this->artisan('erp:create-admin', [
            '--name' => 'Someone Else',
            '--email' => 'owner@hrbd.local',
            '--password' => 'Str0ng!Passphrase22',
        ])->assertFailed();

        $this->assertSame('The Original', User::where('email', 'owner@hrbd.local')->sole()->name);
    }

    #[Test]
    public function promote_assigns_a_role_to_an_existing_account_without_prompting(): void
    {
        // The regression this pins: the command used to ask for a name before
        // it noticed the account already existed, which aborts where there is
        // no terminal to answer.
        $user = User::factory()->create(['email' => 'existing@hrbd.local']);
        $user->assignRole(RoleName::Viewer->value);

        $this->artisan('erp:create-admin', [
            '--email' => 'existing@hrbd.local',
            '--role' => RoleName::SuperAdmin->value,
            '--promote' => true,
        ])->assertSuccessful();

        $this->assertTrue($user->refresh()->hasRole(RoleName::SuperAdmin->value));
        $this->assertFalse($user->hasRole(RoleName::Viewer->value));
    }

    #[Test]
    public function promote_reactivates_a_deactivated_account(): void
    {
        $user = User::factory()->create(['email' => 'existing@hrbd.local']);
        $user->deactivate();

        $this->artisan('erp:create-admin', [
            '--email' => 'existing@hrbd.local',
            '--role' => RoleName::SuperAdmin->value,
            '--promote' => true,
        ])->assertSuccessful();

        $this->assertTrue($user->refresh()->isActive());
    }

    #[Test]
    public function creating_the_administrator_is_recorded_in_the_audit_trail(): void
    {
        $this->artisan('erp:create-admin', [
            '--name' => 'Prashant',
            '--email' => 'owner@hrbd.local',
            '--password' => 'Str0ng!Passphrase22',
        ])->assertSuccessful();

        $user = User::where('email', 'owner@hrbd.local')->sole();

        // The first account is the one nobody authorised, so it had better be
        // the one most clearly on the record.
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'action' => 'roles_changed',
        ]);
    }
}
