<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispatch;

use Illuminate\Foundation\Http\FormRequest;

class DispatchGoodsRequest extends FormRequest
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
            'transporter_name' => ['nullable', 'string', 'max:255'],
            'transporter_gstin' => ['nullable', 'string', 'size:15'],
            'vehicle_number' => ['nullable', 'string', 'max:32'],
            'lr_number' => ['nullable', 'string', 'max:64'],
            'lr_date' => ['nullable', 'date'],
            'eway_bill_number' => ['nullable', 'string', 'digits:12'],
            'eway_bill_date' => ['nullable', 'date', 'required_with:eway_bill_number'],
            'distance_km' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'eway_bill_number.digits' => 'An e-way bill number is 12 digits.',
            'transporter_gstin.size' => 'A transporter ID (GSTIN) is 15 characters.',
        ];
    }
}
