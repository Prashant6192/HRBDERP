<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehousing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeAssignmentRequest extends FormRequest
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
        $facilityId = $this->route('facility')?->id;

        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'store_id' => [
                'nullable', 'integer',
                Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('facility_id', $facilityId)->where('is_system', false)->whereNull('deleted_at')),
            ],
            'is_primary' => ['boolean'],
            'designation' => ['nullable', 'string', 'max:128'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'store_id.exists' => 'Choose a store at this facility.',
            'effective_to.after_or_equal' => 'The end date cannot be before the start date.',
        ];
    }
}
