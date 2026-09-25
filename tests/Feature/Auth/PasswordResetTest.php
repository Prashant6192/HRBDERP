<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditLog;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Features;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An employee who has forgotten their password resets it themselves, from
 * an emailed link, without an administrator — and the form cannot be used
 * to discover who has an account, to flood an inbox, or to bring a
 * deactivated account back.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const string NEW_PASSWORD = 'Rudrapur-Batch-2026!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    private function sentMessage(): string
    {
        return (string) trans('passwords.sent');
    }

    // ---- Asking for the link ------------------------------------------------

    #[Test]
    public function the_forgot_password_screen_renders(): void
    {
        $this->get(route('password.request'))->assertOk();
    }

    #[Test]
    public function an_active_employee_is_emailed_a_link_to_the_reset_screen(): void
    {
        Notification::fake();
        $user = User::factory()->create(['name' => 'Suresh Patil', 'email' => 'suresh@rahatrooh.com']);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36')
            ->post(route('password.email'), ['email' => 'Suresh@RahatRooh.com '])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', $this->sentMessage());

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user) {
            $this->assertSame('Chrome on Windows', $notification->requestedFromDevice);
            $this->assertSame('127.0.0.1', $notification->requestedFromIp);

            $mail = $notification->toMail($user);
            $this->assertSame('Reset your '.config('app.name').' password', $mail->subject);
            $this->assertSame('Hello Suresh,', $mail->greeting);
            $this->assertStringContainsString('/reset-password/'.$notification->token, $mail->actionUrl);
            $this->assertStringContainsString('email=suresh%40rahatrooh.com', $mail->actionUrl);

            // The reset screen the link opens.
            $this->get(route('password.reset', $notification->token))->assertOk();

            return true;
        });
    }

    #[Test]
    public function the_email_is_in_the_erps_words_and_colours(): void
    {
        $user = User::factory()->create(['name' => 'Asha Rawat', 'email' => 'asha@rahatrooh.com']);
        $notification = new ResetPasswordNotification('a-token', '10.0.0.7', 'Safari on iPhone', now()->toImmutable());

        $html = (string) $notification->toMail($user)->render();

        $this->assertStringContainsString('Choose a new password', $html);
        $this->assertStringContainsString('for the next 60 minutes', $html);
        $this->assertStringContainsString('Safari on iPhone, IP address 10.0.0.7', $html);
        $this->assertStringContainsString('Your password has not changed', $html);
        $this->assertStringContainsString('#f59a3d', $html, 'The ERP theme puts its amber on the button');
        $this->assertStringNotContainsString('laravel.com/img', $html, 'No framework logo');
    }

    #[Test]
    public function an_unknown_address_gets_the_same_answer_and_nothing_is_sent(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'nobody@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', $this->sentMessage());

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_deactivated_account_gets_the_same_answer_and_no_email(): void
    {
        Notification::fake();
        $inactive = User::factory()->inactive()->create();
        $suspended = User::factory()->suspended()->create();

        foreach ([$inactive, $suspended] as $user) {
            $this->post(route('password.email'), ['email' => $user->email])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', $this->sentMessage());
        }

        Notification::assertNothingSent();
    }

    #[Test]
    public function one_address_gets_three_emails_in_fifteen_minutes_and_then_quietly_none(): void
    {
        Notification::fake();
        // Take the framework's own one-a-minute pause out of the way, so this
        // measures the per-address limit alone.
        config(['auth.passwords.users.throttle' => 0]);
        $user = User::factory()->create();

        for ($i = 0; $i < PasswordResetLinkController::PER_ADDRESS + 2; $i++) {
            $this->post(route('password.email'), ['email' => $user->email])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', $this->sentMessage());
        }

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, PasswordResetLinkController::PER_ADDRESS);
    }

    #[Test]
    public function one_network_is_told_to_wait_after_ten_requests(): void
    {
        Notification::fake();

        for ($i = 0; $i < PasswordResetLinkController::PER_NETWORK; $i++) {
            $this->post(route('password.email'), ['email' => "person{$i}@example.com"])->assertSessionHasNoErrors();
        }

        $this->post(route('password.email'), ['email' => 'one-more@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('Too many reset requests from this network', session('errors')->first('email'));
    }

    #[Test]
    public function the_address_must_look_like_an_email(): void
    {
        $this->post(route('password.email'), ['email' => 'not-an-address'])->assertSessionHasErrors('email');
    }

    // ---- Using the link -----------------------------------------------------

    #[Test]
    public function the_link_sets_a_new_password_that_signs_in_and_the_reset_is_audited(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $oldRememberToken = $user->remember_token;
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', trans('passwords.reset'));

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse($user->must_change_password, 'They chose their own password; nothing is left to force');
        $this->assertNotSame($oldRememberToken, $user->remember_token, '"Keep me signed in" cookies from before stop working');

        $entry = AuditLog::query()->where('action', AuditAction::PasswordReset->value)->sole();
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame($user->id, (int) $entry->auditable_id);

        // And it works at the door.
        $this->post(route('login.store'), ['email' => $user->email, 'password' => self::NEW_PASSWORD]);
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_link_is_single_use(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $payload = ['token' => $token, 'email' => $user->email, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];

        $this->post(route('password.update'), $payload)->assertSessionHasNoErrors();
        $this->post(route('password.update'), [...$payload, 'password' => 'Another-Pass-2026!', 'password_confirmation' => 'Another-Pass-2026!'])
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    #[Test]
    public function a_link_issued_before_the_account_was_deactivated_no_longer_works(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $user->deactivate();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertStringContainsString('not active', session('errors')->first('email'));
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::PasswordReset->value)->count());
    }

    #[Test]
    public function an_invalid_token_is_refused(): void
    {
        $user = User::factory()->create();

        $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('email');
    }

    // ---- Sessions after a password change ----------------------------------

    #[Test]
    public function a_session_open_elsewhere_is_signed_out_when_the_password_changes(): void
    {
        $user = User::factory()->create();

        // A session that signed in with the old password — a shared PC, a lost phone.
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        // The password is reset from an emailed link somewhere else.
        $user->forceFill(['password' => self::NEW_PASSWORD])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function changing_your_own_password_in_settings_keeps_you_signed_in(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function the_device_is_described_plainly(): void
    {
        $this->assertSame('Safari on iPhone', ResetPasswordNotification::describeDevice('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'));
        $this->assertSame('Edge on Windows', ResetPasswordNotification::describeDevice('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36 Edg/140.0'));
        $this->assertSame('Chrome on Android', ResetPasswordNotification::describeDevice('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Mobile Safari/537.36'));
        $this->assertNull(ResetPasswordNotification::describeDevice(''));
    }
}
