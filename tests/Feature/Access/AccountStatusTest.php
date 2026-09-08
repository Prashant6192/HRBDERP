<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountStatusTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_active_user_can_reach_the_dashboard(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    #[Test]
    public function deactivating_an_account_ends_the_session_it_already_has(): void
    {
        // The case that matters: someone is signed in when their access is
        // withdrawn. Blocking only the next sign-in would leave them working
        // until the session expired.
        $user = User::factory()->create(['status' => UserStatus::Active]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->deactivate();

        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function deactivation_stamps_when_it_happened(): void
    {
        $user = User::factory()->create();

        $user->deactivate(UserStatus::Suspended);

        $this->assertSame(UserStatus::Suspended, $user->refresh()->status);
        $this->assertNotNull($user->deactivated_at);
        $this->assertFalse($user->isActive());
    }

    #[Test]
    public function reactivating_clears_the_deactivation_stamp(): void
    {
        $user = User::factory()->create();

        $user->deactivate();
        $user->activate();

        $this->assertSame(UserStatus::Active, $user->refresh()->status);
        $this->assertNull($user->deactivated_at);
        $this->assertTrue($user->isActive());
    }

    #[Test]
    public function deactivate_refuses_a_status_that_still_permits_sign_in(): void
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $user->deactivate(UserStatus::Active);
    }

    #[Test]
    public function a_suspended_account_is_also_turned_away(): void
    {
        $user = User::factory()->suspended()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function a_soft_deleted_account_cannot_be_used(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->delete();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function the_status_enum_decides_who_may_authenticate(): void
    {
        $this->assertTrue(UserStatus::Active->canAuthenticate());
        $this->assertFalse(UserStatus::Inactive->canAuthenticate());
        $this->assertFalse(UserStatus::Suspended->canAuthenticate());
    }

    #[Test]
    public function a_formula_pin_is_stored_only_as_a_hash(): void
    {
        $user = User::factory()->create();

        $user->setFormulaPin('135790');
        $user->refresh();

        $this->assertNotSame('135790', $user->formula_pin_hash);
        $this->assertTrue($user->verifyFormulaPin('135790'));
        $this->assertFalse($user->verifyFormulaPin('000000'));
    }

    #[Test]
    public function verifying_a_pin_against_an_account_that_has_none_fails_closed(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasFormulaPin());
        $this->assertFalse($user->verifyFormulaPin(''));
        $this->assertFalse($user->verifyFormulaPin('135790'));
    }

    #[Test]
    public function the_formula_pin_is_never_serialised(): void
    {
        $user = User::factory()->withFormulaPin()->create();

        $this->assertArrayNotHasKey('formula_pin_hash', $user->toArray());
        $this->assertArrayNotHasKey('password', $user->toArray());
    }
}
