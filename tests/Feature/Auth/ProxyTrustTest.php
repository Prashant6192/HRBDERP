<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Behind the hosting platform's load balancer, the person's own address
 * and the https scheme arrive as forwarded headers.
 */
class ProxyTrustTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_reset_link_requested_through_the_balancer_is_https_and_names_the_real_ip(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->withHeaders([
            'X-Forwarded-For' => '103.21.58.14',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'erp.rahatrooh.com',
            'X-Forwarded-Port' => '443',
        ])->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user) {
            $this->assertSame('103.21.58.14', $notification->requestedFromIp);
            $this->assertStringStartsWith('https://erp.rahatrooh.com/reset-password/', $notification->toMail($user)->actionUrl);

            return true;
        });
    }
}
