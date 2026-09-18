<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Models;

use App\Domain\Dispatch\Enums\DispatchAttachmentKind;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paper that travelled with a consignment, kept with it.
 *
 * @property int $id
 * @property int $dispatch_id
 * @property DispatchAttachmentKind $kind
 * @property string $path
 * @property string $original_name
 * @property string|null $mime
 * @property int $size
 */
class DispatchAttachment extends Model
{
    protected $fillable = ['dispatch_id', 'kind', 'path', 'original_name', 'mime', 'size', 'uploaded_by'];

    protected function casts(): array
    {
        return [
            'kind' => DispatchAttachmentKind::class,
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Dispatch, $this>
     */
    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
