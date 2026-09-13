<?php

declare(strict_types=1);

namespace App\Http\Requests\Manufacturing;

use App\Domain\Contract\Services\JobCostingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The commercial terms of a third-party job.
 */
class UpdateOrderTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'manufacturing_rate' => ['nullable', 'numeric', 'min:0'],
            'rate_basis' => ['required', Rule::in(JobCostingService::RATE_BASES)],
            'bill_materials' => ['boolean'],
            'material_markup_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'testing' => ['nullable', 'numeric', 'min:0'],
            'development' => ['nullable', 'numeric', 'min:0'],
            'artwork' => ['nullable', 'numeric', 'min:0'],
            'freight' => ['nullable', 'numeric', 'min:0'],
            'other' => ['nullable', 'numeric', 'min:0'],
            'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:28'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['bill_materials' => $this->boolean('bill_materials', true)]);
    }
}
