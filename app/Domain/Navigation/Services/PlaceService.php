<?php

declare(strict_types=1);

namespace App\Domain\Navigation\Services;

use App\Domain\Marketplace\Services\BrandAccess;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Models\User;

/**
 * The places the ERP is arranged by.
 *
 * Work happens somewhere: the factory makes, the depot receives and ships,
 * contract jobs are run for outside brands, and the back office keeps the
 * masters and the people. The navigation is built around those places, so
 * a person opens the place they work in and sees only its departments.
 *
 * Facilities come from the records, not from code: a facility that
 * manufactures is a factory, any other is a depot. Contract work and the
 * company's back office are places too, though neither is a building.
 * A person sees the facilities they are assigned to; company-wide roles
 * see them all.
 */
class PlaceService
{
    /** Words dropped when a facility's name is shortened for the rail. */
    private const array FILLER = ['manufacturing', 'facility', 'factory', 'plant', 'warehouse', 'depot', 'unit', 'store', 'the'];

    public function __construct(
        private readonly FacilityAccess $access,
        private readonly BrandAccess $brands,
    ) {}

    /**
     * @return array{places: list<array{key: string, kind: string, facility_id: int|null, name: string, short: string}>, default: string|null}
     */
    public function for(User $user): array
    {
        // An outside agency only uploads labels; it has no places.
        if ($this->brands->isRestricted($user)) {
            return ['places' => [], 'default' => null];
        }

        $facilities = $this->access->facilitiesFor($user);
        $places = [];

        foreach ($facilities as $facility) {
            /** @var Facility $facility */
            $places[] = [
                'key' => 'f'.$facility->id,
                'kind' => $facility->can_manufacture ? 'factory' : 'depot',
                'facility_id' => $facility->id,
                'name' => $facility->name,
                'short' => $this->short($facility),
            ];
        }

        // Contract jobs run at a factory, for those who plan or cost them.
        $factory = $facilities->first(fn (Facility $f) => $f->can_manufacture);

        if ($factory !== null && ($user->can('client.view') || $user->can('production.view'))) {
            $places[] = [
                'key' => 'contract',
                'kind' => 'contract',
                'facility_id' => $factory->id,
                'name' => '3P Client Manufacturing',
                'short' => '3P Clients',
            ];
        }

        $places[] = ['key' => 'company', 'kind' => 'company', 'facility_id' => null, 'name' => 'Company', 'short' => 'Company'];

        $primary = $this->access->primaryFacility($user);
        $default = $primary !== null && $facilities->contains('id', $primary->id)
            ? 'f'.$primary->id
            : ($places[0]['key'] ?? null);

        return ['places' => $places, 'default' => $default];
    }

    /**
     * "Rudrapur Manufacturing Facility" → "Rudrapur"; "Paper Market
     * Warehouse" → "Paper Market". A name that is all filler is kept.
     */
    public function short(Facility $facility): string
    {
        $words = preg_split('/\s+/', trim($facility->name)) ?: [];
        $kept = array_values(array_filter($words, fn (string $w) => ! in_array(strtolower($w), self::FILLER, true)));

        if ($kept === []) {
            return $facility->city ?: $facility->name;
        }

        return implode(' ', array_slice($kept, 0, 2));
    }
}
