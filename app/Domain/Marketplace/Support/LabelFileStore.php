<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Support;

use App\Domain\Marketplace\Models\LabelFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Where a label PDF is kept: on the app's disk for speed, and in the
 * database so that it outlives a deploy. Reading falls back to the
 * database and puts the file back on the disk.
 */
final class LabelFileStore
{
    public const string DISK = 'local';

    public function put(LabelFile $file, string $contents): void
    {
        Storage::disk(self::DISK)->put($file->path, $contents);

        DB::table('label_file_contents')->upsert(
            [['label_file_id' => $file->id, 'data' => base64_encode($contents), 'created_at' => now(), 'updated_at' => now()]],
            ['label_file_id'],
            ['data', 'updated_at'],
        );
    }

    public function get(LabelFile $file): ?string
    {
        $disk = Storage::disk(self::DISK);

        if ($disk->exists($file->path)) {
            return (string) $disk->get($file->path);
        }

        $data = DB::table('label_file_contents')->where('label_file_id', $file->id)->value('data');

        if ($data === null) {
            return null;
        }

        $contents = (string) base64_decode((string) $data, true);

        try {
            $disk->put($file->path, $contents);
        } catch (Throwable $e) {
            report($e);
        }

        return $contents;
    }

    public function has(LabelFile $file): bool
    {
        return Storage::disk(self::DISK)->exists($file->path)
            || DB::table('label_file_contents')->where('label_file_id', $file->id)->exists();
    }

    public function forget(LabelFile $file): void
    {
        Storage::disk(self::DISK)->delete($file->path);
        DB::table('label_file_contents')->where('label_file_id', $file->id)->delete();
    }
}
