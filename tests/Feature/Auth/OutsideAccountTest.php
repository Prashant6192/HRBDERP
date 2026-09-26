<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Access\Enums\RoleName;
use App\Models\User;
use Database\Seeders\OutsideAccountsSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The digital agency signs in with a username, is not an employee, and
 * reaches only its label uploads.
 */
class OutsideAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OutsideAccountsSeeder::class);
    }

    #[Test]
    public function the_agency_account_is_created_once_as_an_outside_account(): void
    {
        $agency = User::query()->where('username', 'divrit_processing')->sole();

        $this->assertTrue($agency->is_external);
        $this->assertNull($agency->employee_code);
        $this->assertNull($agency->department_id);
        $this->assertTrue($agency->hasRole(RoleName::EcommerceAgency->value));
        $this->assertSame(['Cleanse Ayurveda', 'Rahat Rooh'], $agency->brands()->orderBy('name')->pluck('name')->all());

        // A later deploy leaves it alone: a changed password stays changed.
        $agency->forceFill(['password' => 'A-new-password-9'])->save();
        $this->seed(OutsideAccountsSeeder::class);

        $this->assertSame(1, User::query()->where('username', 'divrit_processing')->count());
        $this->post(route('login.store'), ['email' => 'divrit_processing', 'password' => 'A-new-password-9'])->assertRedirect();
        $this->assertAuthenticatedAs($agency);
    }

    #[Test]
    public function the_agency_signs_in_with_its_username_and_lands_on_online_orders(): void
    {
        $this->post(route('login.store'), ['email' => 'Divrit_Processing', 'password' => 'Divrit@951'])->assertRedirect();
        $this->assertAuthenticatedAs(User::query()->where('username', 'divrit_processing')->sole());

        $this->get(route('dashboard'))->assertRedirect(route('online-orders.index'));
        $this->get(route('online-orders.index'))->assertOk();
    }

    #[Test]
    public function a_wrong_password_is_refused_and_employees_still_sign_in_by_email(): void
    {
        $this->post(route('login.store'), ['email' => 'divrit_processing', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $employee = User::factory()->create(['email' => 'store@hrbd.test', 'password' => 'Store-pass-123']);
        $this->post(route('login.store'), ['email' => 'STORE@hrbd.test', 'password' => 'Store-pass-123'])->assertRedirect();
        $this->assertAuthenticatedAs($employee);
    }

    #[Test]
    public function the_agency_is_not_on_the_employee_list_unless_asked_for(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::SuperAdmin->value);

        $this->actingAs($admin)->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('users.data', fn ($rows) => collect($rows)->doesntContain(fn ($r) => $r['email'] === 'divrit_processing@agency.invalid')));

        $this->actingAs($admin)->get(route('users.index', ['kind' => 'outside']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.email', 'divrit_processing@agency.invalid'));
    }
}
