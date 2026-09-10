<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LockAuditTrailCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_makes_the_trails_append_only_at_the_database(): void
    {
        $entry = AuditLog::create(['action' => AuditAction::Created->value, 'description' => 'before the lock']);

        $this->artisan('erp:lock-audit-trail', ['--check' => true])->assertFailed();
        $this->artisan('erp:lock-audit-trail')->assertSuccessful();
        $this->artisan('erp:lock-audit-trail', ['--check' => true])->assertSuccessful();

        // Inserts still work; nothing else does, even straight through the query builder.
        AuditLog::create(['action' => AuditAction::Created->value, 'description' => 'after the lock']);

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $entry->id)->update(['description' => 'tampered']);
    }
}
