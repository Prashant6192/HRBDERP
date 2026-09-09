<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

/**
 * Hands out the next number for a key, safely under concurrency.
 *
 * The row for the key is locked FOR UPDATE for the duration of the take, so
 * two requests for the same key are served in turn and never receive the same
 * value. Keys include their period — "grn:2609" — so numbering restarts each
 * month or day as a document type requires.
 */
class SequenceService
{
    public function next(string $key): int
    {
        return DB::transaction(function () use ($key): int {
            DocumentSequence::query()->insertOrIgnore([
                'key' => $key,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DocumentSequence::query()
                ->where('key', $key)
                ->lockForUpdate()
                ->firstOrFail();

            $value = $row->next_value;

            $row->update(['next_value' => $value + 1]);

            return $value;
        });
    }

    /**
     * A formatted document number: PREFIX-PERIOD-00001.
     */
    public function nextNumber(string $prefix, string $period, int $width = 5, string $separator = '-'): string
    {
        $value = $this->next(strtolower($prefix).':'.$period);

        return sprintf('%s%s%s%s%0'.$width.'d', $prefix, $separator, $period, $separator, $value);
    }
}
