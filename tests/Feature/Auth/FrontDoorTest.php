<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The bare address is the way in: there is no public front page.
 */
class FrontDoorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_visitor_at_the_bare_address_is_sent_to_sign_in(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    #[Test]
    public function someone_already_signed_in_goes_straight_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }
}
