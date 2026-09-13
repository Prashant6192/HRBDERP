<?php

declare(strict_types=1);

namespace App\Http\Controllers\Intelligence;

use App\Domain\Formulation\Models\Formula;
use App\Domain\Intelligence\Services\WhatIfService;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\FacilityCapability;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What-if production simulation. Reads only; nothing is planned.
 */
class WhatIfController extends Controller
{
    public function __construct(
        private readonly WhatIfService $whatIf,
        private readonly FacilityAccess $access,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('planning.view');

        $formulas = Formula::query()
            ->active()
            ->whereNotNull('active_version_id')
            ->with(['product:id,name,net_content,net_content_uom_id', 'product.netContentUom:id,code', 'activeVersion:id,version_number,batch_uom_id', 'activeVersion.batchUom:id,code', 'client:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (Formula $f) => [
                'value' => $f->id,
                'label' => "{$f->name} ({$f->code})",
                'product' => $f->product?->name,
                'client' => $f->client?->name,
                'batch_uom_id' => $f->activeVersion?->batch_uom_id,
                'batch_uom' => $f->activeVersion?->batchUom?->code,
                'net_content' => $f->product?->net_content,
                'net_content_uom' => $f->product?->netContentUom?->code,
            ])
            ->values()
            ->all();

        $facilities = $this->access->facilitiesFor($request->user())
            ->filter(fn (Facility $f) => $f->can(FacilityCapability::Manufacture))
            ->map(fn (Facility $f) => ['id' => $f->id, 'code' => $f->code, 'name' => $f->name, 'daily_capacity_kg' => $f->daily_capacity_kg])
            ->values()
            ->all();

        $input = [
            'formula_id' => $request->integer('formula_id') ?: null,
            'quantity' => $request->string('quantity')->toString(),
            'uom_id' => $request->integer('uom_id') ?: null,
            'facility_id' => $request->integer('facility_id') ?: null,
            'start' => $request->string('start')->toString() ?: null,
        ];

        $result = null;

        if ($input['formula_id'] && is_numeric($input['quantity']) && (float) $input['quantity'] > 0) {
            $formula = Formula::query()->find($input['formula_id']);
            $uom = $input['uom_id'] ? Uom::query()->find($input['uom_id']) : null;
            $facility = $input['facility_id'] ? collect($facilities)->firstWhere('id', $input['facility_id']) : null;
            $facilityModel = $facility ? Facility::query()->find($facility['id']) : null;

            if ($formula !== null) {
                $uom ??= $formula->activeVersion?->batchUom;
            }

            if ($formula !== null && $uom !== null) {
                $start = $input['start'] ? CarbonImmutable::parse($input['start']) : null;
                $result = $this->whatIf->simulate($formula, $input['quantity'], $uom, $facilityModel, $start);
            }
        }

        return Inertia::render('planning/simulate', [
            'formulas' => $formulas,
            'facilities' => $facilities,
            'uoms' => Uom::query()->where('is_active', true)->orderBy('dimension')->orderBy('code')->get(['id', 'code', 'name', 'dimension'])->all(),
            'input' => $input,
            'result' => $result,
        ]);
    }
}
