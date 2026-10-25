<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Access\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * erp:reviewer: a login for looking over a preview copy, never the live site.
 */
class ReviewerCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function it_makes_a_reviewer_with_a_fresh_password_on_a_preview_copy(): void
    {
        config(['app.url' => 'https://hrbd-preview.laravel.cloud', 'erp.live_host' => 'erp.rahatrooh.com']);

        $this->assertSame(0, Artisan::call('erp:reviewer'));
        preg_match('/Password:\s+(\S+)/', Artisan::output(), $m);

        $user = User::query()->where('email', 'review@hrbd.local')->sole();
        $this->assertTrue($user->hasRole(RoleName::SuperAdmin->value));
        $this->assertTrue(Hash::check($m[1], $user->password));

        // Run again: the same login, a new password.
        Artisan::call('erp:reviewer');
        preg_match('/Password:\s+(\S+)/', Artisan::output(), $again);
        $this->assertNotSame($m[1], $again[1]);
        $this->assertSame(1, User::query()->where('email', 'review@hrbd.local')->count());
    }

    #[Test]
    public function it_refuses_on_the_live_site(): void
    {
        config(['app.url' => 'https://erp.rahatrooh.com', 'erp.live_host' => 'erp.rahatrooh.com']);

        $this->artisan('erp:reviewer')->assertFailed();
        $this->assertFalse(User::query()->where('email', 'review@hrbd.local')->exists());
    }
}
