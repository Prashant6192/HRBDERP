<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_a_record_writes_an_audit_entry(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $warehouse = Warehouse::factory()->create(['name' => 'Raw Material Store']);

        $entry = AuditLog::forEntity($warehouse)->action(AuditAction::Created)->sole();

        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame($user->name, $entry->user_name);
        $this->assertSame('Raw Material Store', $entry->new_values['name']);
    }

    #[Test]
    public function updating_a_record_captures_only_what_changed(): void
    {
        $this->actingAs(User::factory()->create());

        $warehouse = Warehouse::factory()->create(['name' => 'Old Name', 'city' => 'Pune']);
        $warehouse->update(['name' => 'New Name']);

        $entry = AuditLog::forEntity($warehouse)->action(AuditAction::Updated)->sole();

        $this->assertSame(['name' => 'Old Name'], $entry->old_values);
        $this->assertSame(['name' => 'New Name'], $entry->new_values);

        // The untouched column is absent rather than recorded as unchanged.
        $this->assertArrayNotHasKey('city', $entry->new_values);
    }

    #[Test]
    public function the_changes_helper_pairs_old_and_new_values(): void
    {
        $this->actingAs(User::factory()->create());

        $warehouse = Warehouse::factory()->create(['name' => 'Before']);
        $warehouse->update(['name' => 'After']);

        $entry = AuditLog::forEntity($warehouse)->action(AuditAction::Updated)->sole();

        $this->assertSame(
            ['name' => ['old' => 'Before', 'new' => 'After']],
            $entry->changes(),
        );
    }

    #[Test]
    public function an_update_that_changes_nothing_auditable_writes_no_entry(): void
    {
        $this->actingAs(User::factory()->create());

        $warehouse = Warehouse::factory()->create();
        $countAfterCreate = AuditLog::forEntity($warehouse)->count();

        $warehouse->touch();

        $this->assertSame($countAfterCreate, AuditLog::forEntity($warehouse)->count());
    }

    #[Test]
    public function secrets_are_never_written_to_the_audit_trail(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);

        $user = User::factory()->create();
        $user->setFormulaPin('135790');

        $entries = AuditLog::forEntity($user)->get();

        foreach ($entries as $entry) {
            $recorded = array_merge($entry->old_values ?? [], $entry->new_values ?? []);

            $this->assertArrayNotHasKey('password', $recorded);
            $this->assertArrayNotHasKey('formula_pin_hash', $recorded);
            $this->assertArrayNotHasKey('remember_token', $recorded);
            $this->assertArrayNotHasKey('two_factor_secret', $recorded);
        }
    }

    #[Test]
    public function an_audit_entry_cannot_be_updated(): void
    {
        $this->actingAs(User::factory()->create());
        $warehouse = Warehouse::factory()->create();

        $entry = AuditLog::forEntity($warehouse)->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $entry->update(['action' => AuditAction::Deleted->value]);
    }

    #[Test]
    public function an_audit_entry_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $warehouse = Warehouse::factory()->create();

        $entry = AuditLog::forEntity($warehouse)->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $entry->delete();
    }

    #[Test]
    public function an_audit_entry_survives_the_record_it_describes(): void
    {
        $this->actingAs(User::factory()->create());

        $warehouse = Warehouse::factory()->create(['code' => 'WH-GONE', 'name' => 'Closing Store']);
        $warehouseId = $warehouse->id;

        $warehouse->delete();

        $entry = AuditLog::where('auditable_type', Warehouse::class)
            ->where('auditable_id', $warehouseId)
            ->action(AuditAction::Deleted)
            ->sole();

        // The label was copied at the time, so the entry still names the thing
        // it refers to.
        $this->assertSame('WH-GONE — Closing Store', $entry->auditable_label);
    }

    #[Test]
    public function a_successful_sign_in_is_recorded_and_stamps_the_account(): void
    {
        $user = User::factory()->create(['email' => 'clerk@hrbd.local']);

        $this->post(route('login.store'), [
            'email' => 'clerk@hrbd.local',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertNotNull($user->refresh()->last_login_at);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => AuditAction::LoggedIn->value,
        ]);
    }

    #[Test]
    public function a_failed_sign_in_is_recorded_without_the_password(): void
    {
        $user = User::factory()->create(['email' => 'clerk@hrbd.local']);

        $this->post(route('login.store'), [
            'email' => 'clerk@hrbd.local',
            'password' => 'not-the-password',
        ]);

        $this->assertGuest();

        $entry = AuditLog::action(AuditAction::LoginFailed)->sole();

        $this->assertSame('clerk@hrbd.local', $entry->context['email']);
        $this->assertStringNotContainsString('not-the-password', json_encode($entry->toArray()));
    }
}
