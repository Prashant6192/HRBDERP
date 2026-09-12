<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehousing;

use App\Domain\Warehousing\Enums\FacilityCapability;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFacilityRequest extends FormRequest
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
            ...FacilityRules::details($this->route('facility')?->id),
            ...FacilityRules::capabilities(),
            'opening_stock_enabled' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return FacilityRules::messages();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->filled('code') ? strtoupper(trim((string) $this->input('code'))) : null,
            'gstin' => $this->filled('gstin') ? strtoupper(trim((string) $this->input('gstin'))) : null,
        ]);

        foreach (FacilityCapability::cases() as $capability) {
            if ($this->has($capability->value)) {
                $this->merge([$capability->value => filter_var($this->input($capability->value), FILTER_VALIDATE_BOOL)]);
            }
        }
    }
}
