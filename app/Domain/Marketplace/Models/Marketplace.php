<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\Marketplace\Enums\LabelReaderKind;
use Illuminate\Database\Eloquent\Model;

/**
 * Meesho, Flipkart, Amazon, Myntra — and how each one's labels are read.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property LabelReaderKind $reader
 * @property int|null $claim_window_hours
 * @property bool $is_active
 */
class Marketplace extends Model
{
    protected $fillable = ['code', 'name', 'reader', 'claim_window_hours', 'is_active'];

    protected function casts(): array
    {
        return [
            'reader' => LabelReaderKind::class,
            'claim_window_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
