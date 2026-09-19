<?php

declare(strict_types=1);

namespace App\Http\Requests\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;

class CompleteManufacturingOrderRequest extends FormRequest
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
            'output_quantity' => ['required', 'numeric', 'gt:0'],
            'output_units' => ['nullable', 'integer', 'min:1'],
            // The batch account: filled, rejected at packing, kept as
            // samples, and bulk that was made but not packed.
            'filled_units' => ['nullable', 'integer', 'min:0'],
            'rejected_units' => ['nullable', 'integer', 'min:0'],
            'sample_units' => ['nullable', 'integer', 'min:0'],
            'bulk_leftover_quantity' => ['nullable', 'numeric', 'min:0'],
            'loss_notes' => ['nullable', 'string', 'max:1000'],
            'manufactured_at' => ['nullable', 'date', 'before_or_equal:today'],
            'expiry_at' => ['nullable', 'date', 'after:manufactured_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'output_quantity.gt' => 'Enter how much came out of the batch.',
            'rejected_units.min' => 'Rejected units cannot be negative.',
            'sample_units.min' => 'Sample units cannot be negative.',
            'bulk_leftover_quantity.min' => 'Bulk left over cannot be negative.',
            'expiry_at.after' => 'Expiry must be after the manufacturing date.',
            'manufactured_at.before_or_equal' => 'The manufacturing date cannot be in the future.',
        ];
    }
}
