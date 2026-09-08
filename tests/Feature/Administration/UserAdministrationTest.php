<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole(RoleName::SuperAdmin->value);
    }

    #[Test]
    public function a_super_admin_can_create_a_user_with_roles(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('users.store'), [
                'name' => 'Imran Sheikh',
                'email' => 'imran@hrbd.local',
                'password' => 'correct-horse-battery-staple',
                'password_confirmation' => 'correct-horse-battery-staple',
                'status' => UserStatus::Active->value,
                'roles' => [RoleName::WarehouseManager->value],
            ])
            ->assertRedirect(route('users.index'));

        $user = User::where('email', 'imran@hrbd.local')->sole();

        $this->assertTrue($user->hasRole(RoleName::WarehouseManager->value));
        $this->assertTrue($user->can('inventory.receive'));
    }

    #[Test]
    public function the_password_is_hashed_not_stored(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('users.store'), [
                'name' => 'Hash Test',
                'email' => 'hash@hrbd.local',
                'password' => 'correct-horse-battery-staple',
                'password_confirmation' => 'correct-horse-battery-staple',
                'status' => UserStatus::Active->value,
            ]);

        $user = User::where('email', 'hash@hrbd.local')->sole();

        $this->assertNotSame('correct-horse-battery-staple', $user->password);
        $this->assertTrue(password_verify('correct-horse-battery-staple', $user->password));
    }

    #[Test]
    public function a_mismatched_password_confirmation_is_rejected(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('users.store'), [
                'name' => 'Mismatch',
                'email' => 'mismatch@hrbd.local',
                'password' => 'correct-horse-battery-staple',
                'password_confirmation' => 'something-else-entirely',
                'status' => UserStatus::Active->value,
            ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'mismatch@hrbd.local']);
    }

    #[Test]
    public function a_role_that_is_not_super_admin_cannot_hand_out_roles(): void
    {
        // Role assignment is the one permission that can be used to grant
        // every other, so it is restricted beyond user.edit.
        $owner = User::factory()->create();
        $owner->assignRole(RoleName::Owner->value);

        $target = User::factory()->create();
        $target->assignRole(RoleName::Viewer->value);

        $this->actingAs($owner)
            ->put(route('users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'status' => UserStatus::Active->value,
                'roles' => [RoleName::SuperAdmin->value],
            ])
            ->assertRedirect();

        $this->assertFalse($target->refresh()->hasRole(RoleName::SuperAdmin->value));
        $this->assertTrue($target->hasRole(RoleName::Viewer->value));
    }

    #[Test]
    public function nobody_can_grant_themselves_a_role(): void
    {
        $target = User::factory()->create();
        $target->assignRole(RoleName::Viewer->value);

        $this->assertFalse($this->superAdmin->can('assignRoles', $this->superAdmin));
        $this->assertTrue($this->superAdmin->can('assignRoles', $target));
    }

    #[Test]
    public function changing_roles_is_audited_with_the_before_and_after(): void
    {
        $target = User::factory()->create();
        $target->assignRole(RoleName::Viewer->value);

        $this->actingAs($this->superAdmin)
            ->put(route('users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'status' => UserStatus::Active->value,
                'roles' => [RoleName::QcManager->value],
            ]);

        $entry = AuditLog::forEntity($target)->action(AuditAction::RolesChanged)->sole();

        $this->assertSame([RoleName::Viewer->value], $entry->old_values['roles']);
        $this->assertSame([RoleName::QcManager->value], $entry->new_values['roles']);
    }

    #[Test]
    public function an_administrator_can_deactivate_and_reactivate_an_account(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->superAdmin)
            ->post(route('users.deactivate', $target))
            ->assertRedirect(route('users.show', $target));

        $this->assertSame(UserStatus::Inactive, $target->refresh()->status);
        $this->assertNotNull($target->deactivated_at);

        $this->actingAs($this->superAdmin)
            ->post(route('users.activate', $target))
            ->assertRedirect(route('users.show', $target));

        $this->assertSame(UserStatus::Active, $target->refresh()->status);
        $this->assertNull($target->deactivated_at);
    }

    #[Test]
    public function an_administrator_cannot_deactivate_themselves(): void
    {
        // Locking yourself out is the one mistake with no way back.
        $this->actingAs($this->superAdmin)
            ->post(route('users.deactivate', $this->superAdmin))
            ->assertForbidden();

        $this->assertSame(UserStatus::Active, $this->superAdmin->refresh()->status);
    }

    #[Test]
    public function leaving_the_password_blank_on_an_edit_keeps_the_existing_one(): void
    {
        $target = User::factory()->create();
        $originalHash = $target->password;

        $this->actingAs($this->superAdmin)
            ->put(route('users.update', $target), [
                'name' => 'Renamed Person',
                'email' => $target->email,
                'status' => UserStatus::Active->value,
                'password' => '',
            ])
            ->assertSessionHasNoErrors();

        $target->refresh();

        $this->assertSame('Renamed Person', $target->name);
        $this->assertSame($originalHash, $target->password);
    }

    #[Test]
    public function a_user_without_the_permission_cannot_reach_user_administration(): void
    {
        $designer = User::factory()->create();
        $designer->assignRole(RoleName::Designer->value);

        $this->actingAs($designer)->get(route('users.index'))->assertForbidden();
        $this->actingAs($designer)->get(route('users.create'))->assertForbidden();
    }
}
