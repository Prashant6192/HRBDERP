<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\DTOs\FactoryException;
use App\Domain\Intelligence\Models\Escalation;
use App\Models\User;
use App\Notifications\ErpAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Automatic escalation.
 *
 * If something stays pending too long, the people responsible are told,
 * and if it stays longer still, their seniors are. The ladder for each
 * exception rule is in config/erp.php under `escalation`: after how many
 * hours, which roles. Each level fires once per exception; when the
 * condition clears the escalation is closed, so a later recurrence starts
 * the ladder again.
 */
class EscalationService
{
    public function __construct(private readonly ExceptionService $exceptions) {}

    /**
     * Run one pass: raise what is due, close what has cleared.
     *
     * @return array{raised: int, resolved: int, standing: int}
     */
    public function run(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $exceptions = $this->exceptions->detect(null, $asOf)->keyBy(fn (FactoryException $e) => $e->key());
        $ladders = (array) config('erp.escalation', []);

        $raised = 0;

        foreach ($exceptions as $key => $exception) {
            $ladder = $ladders[$exception->rule] ?? [];

            foreach (array_values($ladder) as $index => $step) {
                $level = $index + 1;
                $after = (float) ($step['after_hours'] ?? 0);

                if ($exception->ageHours($asOf) < $after) {
                    continue;
                }

                if (Escalation::query()->where('exception_key', $key)->where('level', $level)->exists()) {
                    continue;
                }

                $roles = array_values((array) ($step['roles'] ?? []));
                $recipients = $this->recipients($roles);

                foreach ($recipients as $user) {
                    $user->notify(new ErpAlert(
                        title: $exception->title,
                        body: $exception->detail.($after > 0 ? " Pending for {$exception->ageHours($asOf)} hours." : ''),
                        href: $exception->href,
                        severity: $exception->severity,
                        category: 'escalation',
                        key: $key,
                    ));
                }

                Escalation::query()->create([
                    'exception_key' => $key,
                    'rule' => $exception->rule,
                    'level' => $level,
                    'title' => $exception->title,
                    'href' => $exception->href,
                    'roles' => $roles,
                    'recipients' => $recipients->count(),
                    'escalated_at' => $asOf,
                ]);

                $raised++;
            }
        }

        // Anything escalated that is no longer an exception has cleared.
        $resolved = Escalation::query()
            ->standing()
            ->whereNotIn('exception_key', $exceptions->keys()->all())
            ->update(['resolved_at' => $asOf]);

        return [
            'raised' => $raised,
            'resolved' => $resolved,
            'standing' => Escalation::query()->standing()->count(),
        ];
    }

    /**
     * Active users holding any of the roles.
     *
     * @param  list<string>  $roles
     * @return Collection<int, User>
     */
    private function recipients(array $roles): Collection
    {
        if ($roles === []) {
            return collect();
        }

        return User::query()
            ->role($roles)
            ->where('status', 'active')
            ->get()
            ->unique('id')
            ->values();
    }
}
