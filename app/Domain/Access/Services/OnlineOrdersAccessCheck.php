<?php

declare(strict_types=1);

namespace App\Domain\Access\Services;

use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Models\LabelBatch;
use App\Domain\Marketplace\Services\BrandAccess;
use App\Domain\Marketplace\Support\Cutoff;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Models\User;

/**
 * "Why can't Shanu see today's labels?" — answered on the user's page, in
 * plain words, from the same rules the Online orders screen uses: the
 * role's permissions, the places the person works, and where today's
 * uploads landed.
 */
final class OnlineOrdersAccessCheck
{
    public function __construct(
        private readonly FacilityAccess $facilities,
        private readonly BrandAccess $brands,
    ) {}

    /**
     * @return list<array{ok: bool, text: string}>
     */
    public function for(User $user): array
    {
        $first = strtok($user->name, ' ') ?: $user->name;
        $lines = [];

        if (! $user->can('marketplace.view')) {
            return [[
                'ok' => false,
                'text' => "{$first} cannot open Online orders: none of their roles allows it. Give them the Warehouse Manager role (print, pack, hand over) or Store Executive (pack, hand over).",
            ]];
        }

        $abilities = collect([
            'print' => $user->can('marketplace.print'),
            'pack' => $user->can('marketplace.pack'),
            'hand over' => $user->can('marketplace.handover'),
            'upload' => $user->can('marketplace.upload'),
        ])->filter()->keys();

        $lines[] = ['ok' => true, 'text' => "{$first} can open Online orders".($abilities->isEmpty() ? ' (view only).' : ' and '.$abilities->implode(', ').'.')];

        $ids = $this->facilities->facilityIds($user);
        $names = $ids === null ? null : Facility::query()->whereKey($ids)->orderBy('name')->pluck('name');

        $lines[] = $ids === null
            ? ['ok' => true, 'text' => "{$first} works across every facility, so sees every facility's labels."]
            : ($names->isEmpty()
                ? ['ok' => false, 'text' => "{$first} is not assigned to any facility."]
                : ['ok' => true, 'text' => "{$first} sees the labels of: ".$names->implode(', ').'.']);

        $today = LabelBatch::query()
            ->whereDate('for_date', Cutoff::today()->toDateString())
            ->with(['facility:id,name', 'warehouse:id,name'])
            ->withCount(['shipments as parcels' => fn ($q) => $q->whereNotIn('status', [ShipmentStatus::Cancelled->value, ShipmentStatus::Returned->value])])
            ->get();

        if ($today->isEmpty()) {
            $lines[] = ['ok' => true, 'text' => 'No labels have been uploaded for today yet.'];

            return $lines;
        }

        $brandIds = $this->brands->brandIds($user);
        $visible = $today
            ->when($ids !== null, fn ($c) => $c->whereIn('facility_id', $ids))
            ->when($brandIds !== null, fn ($c) => $c->whereIn('brand_id', $brandIds));
        $where = $today->groupBy('facility_id')->map(fn ($g) => $g->first()->facility?->name.' ('.$g->first()->warehouse?->name.'): '.$g->sum('parcels').' parcels')->implode('; ');

        if ($visible->count() === $today->count()) {
            $lines[] = ['ok' => true, 'text' => "Today's labels — {$where} — are all visible to {$first}."];
        } else {
            $hidden = $today->diff($visible);
            $lines[] = ['ok' => false, 'text' => sprintf(
                "Today's labels are at %s. %s cannot see %d parcel(s) at %s, because they do not work there. Assign them to that facility on this page, or, if the labels went to the wrong store, change the brand's store on Online orders → Brands.",
                $where,
                $first,
                $hidden->sum('parcels'),
                $hidden->map(fn ($b) => $b->facility?->name)->unique()->implode(', '),
            )];
        }

        return $lines;
    }
}
