<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Domain\Warehousing\Enums\FacilityCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FacilityTypeRequest extends FormRequest
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
        $rules = [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-]+$/', Rule::unique('facility_types', 'code')->ignore($this->route('facilityType')?->id)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'default_capabilities' => ['nullable', 'array'],
        ];

        foreach (FacilityCapability::cases() as $capability) {
            $rules["default_capabilities.{$capability->value}"] = ['boolean'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
    }
}
