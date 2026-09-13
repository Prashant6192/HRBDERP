<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement;

use Illuminate\Foundation\Http\FormRequest;

class IntakeInvoiceRequest extends FormRequest
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
            'invoice' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:15360'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'invoice.required' => 'Choose the bill to upload.',
            'invoice.mimes' => 'Upload the bill as a PDF or a JPEG / PNG / WebP photo.',
            'invoice.max' => 'The file is larger than 15 MB. Scan at a lower resolution.',
        ];
    }
}
