<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Services;

use App\Domain\Contract\Services\JobCostingService;
use App\Domain\Intelligence\Services\ExceptionService;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use Brick\Math\BigDecimal;

/**
 * Approval by risk, not just amount.
 *
 * Instead of "a PO above ₹50,000 needs the director", the triggers are
 * about what could go wrong: a batch on a recipe version that is not the
 * active one, a third-party job that loses money, a product whose recent
 * yield is below target, abnormal wastage on the materials it uses, a
 * material whose last delivery came in dearer. Any trigger sends the
 * release to a second person.
 */
class RiskAssessor
{
    public function __construct(
        private readonly JobCostingService $costing,
        private readonly ExceptionService $exceptions,
    ) {}

    /**
     * The reasons a manufacturing release needs a second signature; empty
     * when it may go straight through.
     *
     * @return list<array{key: string, reason: string}>
     */
    public function forManufacturingRelease(ManufacturingOrder $order): array
    {
        $order->loadMissing(['formula', 'formulaVersion', 'product', 'lines.item:id,code,name']);
        $triggers = [];

        if ($order->formula !== null && $order->formula->active_version_id !== $order->formula_version_id) {
            $triggers[] = ['key' => 'non_standard_formula', 'reason' => "The batch uses v{$order->formulaVersion?->version_number} of {$order->formula->name}, which is not the active recipe."];
        }

        if ($order->isThirdParty()) {
            $costing = $this->costing->forOrder($order);

            if ($costing['has_terms'] && BigDecimal::of($costing['margin'])->isNegative()) {
                $triggers[] = ['key' => 'negative_margin', 'reason' => 'At the recorded terms this client job would lose money: margin ₹'.number_format((float) $costing['margin'], 0).'.'];
            }

            if (! $costing['has_terms']) {
                $triggers[] = ['key' => 'no_terms', 'reason' => 'No commercial terms are recorded for this client job.'];
            }
        }

        $exceptions = $this->exceptions->detect(null);
        $itemCodes = $order->lines->map(fn ($l) => $l->item?->code)->filter()->all();

        foreach ($exceptions as $e) {
            if ($e->rule === 'production_below_target' && $order->product_id !== null && str_contains($e->detail, (string) $order->product?->name)) {
                $triggers[] = ['key' => 'yield_below_target', 'reason' => $e->title.': '.$e->detail];
            }

            if ($e->rule === 'abnormal_wastage' && in_array($e->subject, $itemCodes, true)) {
                $triggers[] = ['key' => 'abnormal_wastage', 'reason' => $e->title.' on a material this batch uses.'];
            }

            if ($e->rule === 'price_increase' && in_array(explode(':', $e->subject)[0], $itemCodes, true)) {
                $triggers[] = ['key' => 'price_increase', 'reason' => $e->title.' — a material this batch uses.'];
            }

            if ($e->rule === 'material_variance' && in_array($e->subject, $itemCodes, true)) {
                $triggers[] = ['key' => 'material_variance', 'reason' => $e->title.' on a material this batch uses.'];
            }
        }

        return array_values(array_unique($triggers, SORT_REGULAR));
    }
}
