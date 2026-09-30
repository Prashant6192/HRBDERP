<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Support;

use App\Domain\Marketplace\Models\LabelFile;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Where a label PDF is kept: on the app's disk for speed, and in the
 * database so that it outlives a deploy. The database copy is the one
 * that counts: a disk that is down, or a storage bucket that is not set
 * up right, is written to the log and never stops an upload or a print.
 */
final class LabelFileStore
{
    public const string DISK = 'files';

    public function put(LabelFile $file, string $contents): void
    {
        DB::table('label_file_contents')->upsert(
            [['label_file_id' => $file->id, 'data' => base64_encode($contents), 'created_at' => now(), 'updated_at' => now()]],
            ['label_file_id'],
            ['data', 'updated_at'],
        );

        $this->quietly(fn () => Storage::disk(self::DISK)->put($file->path, $contents));
    }

    public function get(LabelFile $file): ?string
    {
        $fromDisk = $this->quietly(function () use ($file): ?string {
            $disk = Storage::disk(self::DISK);

            return $disk->exists($file->path) ? (string) $disk->get($file->path) : null;
        });

        if (is_string($fromDisk) && $fromDisk !== '') {
            return $fromDisk;
        }

        $data = DB::table('label_file_contents')->where('label_file_id', $file->id)->value('data');

        if ($data === null) {
            return null;
        }

        $contents = (string) base64_decode((string) $data, true);
        $this->quietly(fn () => Storage::disk(self::DISK)->put($file->path, $contents));

        return $contents;
    }

    public function has(LabelFile $file): bool
    {
        return DB::table('label_file_contents')->where('label_file_id', $file->id)->exists()
            || $this->quietly(fn () => Storage::disk(self::DISK)->exists($file->path)) === true;
    }

    public function forget(LabelFile $file): void
    {
        $this->discard($file->path);
        DB::table('label_file_contents')->where('label_file_id', $file->id)->delete();
    }

    /**
     * Take a file off the disk, if it is there and the disk answers.
     */
    public function discard(string $path): void
    {
        $this->quietly(fn () => Storage::disk(self::DISK)->delete($path));
    }

    private function quietly(Closure $work): mixed
    {
        try {
            return $work();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
