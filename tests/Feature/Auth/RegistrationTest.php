<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Self-registration is closed.
 *
 * This is a company ERP: accounts are created by an administrator who assigns
 * the roles deciding what the person may reach. An account somebody made for
 * themselves would carry no roles, so it could do nothing useful — but it would
 * still be an unexplained row in the user list and a foothold for anyone who
 * found the address.
 *
 * These tests exist so that re-enabling Fortify's registration feature has to
 * be a deliberate act with a failing test to explain itself, rather than
 * something that comes back unnoticed on a package update.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function there_is_no_registration_route(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('register.store'));
    }

    #[Test]
    public function the_registration_urls_are_not_reachable(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Uninvited Person',
            'email' => 'uninvited@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'uninvited@example.com']);
    }

    #[Test]
    public function signing_in_still_works(): void
    {
        // Closing registration must not disturb the way people actually get in.
        $user = User::factory()->create(['email' => 'employee@hrbd.local']);

        $this->post(route('login.store'), [
            'email' => 'employee@hrbd.local',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function password_reset_is_still_available(): void
    {
        // An employee who forgets their password must not need an administrator.
        $this->assertTrue(Route::has('password.request'));
        $this->get(route('password.request'))->assertOk();
    }
}
