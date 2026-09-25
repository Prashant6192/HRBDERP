<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A label PDF exactly as the marketplace produced it. Kept whole: printing
 * picks pages out of it, nothing is ever written back.
 *
 * @property int $id
 * @property int $label_batch_id
 * @property string $path
 * @property string $original_name
 * @property int $size
 * @property int $pages
 * @property string $sha256
 * @property string|null $read_with
 * @property list<string>|null $warnings
 */
class LabelFile extends Model
{
    protected $fillable = [
        'label_batch_id', 'path', 'original_name', 'size', 'pages', 'sha256', 'read_with', 'read_model', 'read_at', 'warnings', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'pages' => 'integer',
            'read_at' => 'datetime',
            'warnings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<LabelBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(LabelBatch::class, 'label_batch_id');
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
