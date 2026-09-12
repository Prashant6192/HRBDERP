<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehousing;

use App\Domain\Warehousing\Enums\FacilityCapability;
use Illuminate\Validation\Rule;

/**
 * Validation shared by the create and edit facility requests.
 */
final class FacilityRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function details(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-]+$/', Rule::unique('facilities', 'code')->ignore($ignoreId)],
            'facility_type_id' => ['required', 'integer', Rule::exists('facility_types', 'id')->where('is_active', true)],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'state' => ['nullable', 'string', 'max:128'],
            'pincode' => ['nullable', 'string', 'max:16'],
            'country' => ['nullable', 'string', 'max:128'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'string', 'size:15'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function capabilities(): array
    {
        $rules = [];

        foreach (FacilityCapability::cases() as $capability) {
            $rules[$capability->value] = ['boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'code.unique' => 'A facility with this code already exists.',
            'code.regex' => 'Facility codes use letters, digits and dashes only, e.g. FAC-RDP-001.',
            'gstin.size' => 'A GSTIN is exactly 15 characters.',
            'facility_type_id.exists' => 'Choose an active facility type.',
        ];
    }
}
