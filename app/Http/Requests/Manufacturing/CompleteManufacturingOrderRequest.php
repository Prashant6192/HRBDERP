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
            'expiry_at.after' => 'Expiry must be after the manufacturing date.',
            'manufactured_at.before_or_equal' => 'The manufacturing date cannot be in the future.',
        ];
    }
}
