<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A print run: who printed which labels of a batch, and when.
 *
 * @property int $id
 * @property int $label_batch_id
 * @property int|null $printed_by
 * @property string $scope
 * @property string|null $courier
 * @property int $shipment_count
 * @property int $page_count
 */
class LabelPrint extends Model
{
    protected $fillable = ['label_batch_id', 'printed_by', 'scope', 'courier', 'shipment_count', 'page_count'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
