<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Concerns;

use App\Domain\MasterData\Enums\ItemType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restricts a model to one row of the items table's type column.
 *
 * A model using this trait behaves as though it had its own table: queries are
 * scoped to the type, and new records are stamped with it, so no caller has to
 * remember either.
 *
 * The using class must declare:
 *
 *   public static function itemType(): ItemType
 */
trait ConstrainedToItemType
{
    public static function bootConstrainedToItemType(): void
    {
        $type = static::itemType();

        static::addGlobalScope(
            'item_type',
            static fn (Builder $query) => $query->where(
                $query->getModel()->qualifyColumn('type'),
                $type->value,
            ),
        );
    }

    /**
     * Stamp the type on every new instance.
     *
     * Eloquent calls initialize<Trait> from the model constructor, so the
     * discriminator is set the moment the object exists rather than on the way
     * to the database. That matters because model events can be suppressed —
     * seeders routinely do it — and a discriminator that depends on an event
     * firing is a NOT NULL violation waiting to happen.
     */
    public function initializeConstrainedToItemType(): void
    {
        $this->setAttribute('type', static::itemType());
    }

    abstract public static function itemType(): ItemType;
}
