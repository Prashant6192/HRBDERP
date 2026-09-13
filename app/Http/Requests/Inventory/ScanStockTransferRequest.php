<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class ScanStockTransferRequest extends FormRequest
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
            // The QR's contents, the inward code, or the number and the code.
            'code' => ['nullable', 'string', 'max:512', 'required_without:document'],
            // The transporter's invoice / LR, or the challan itself.
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:15360', 'required_without:code'],
            'transport_reference' => ['nullable', 'string', 'max:64'],
            'transporter' => ['nullable', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required_without' => 'Scan the QR on the challan, type the inward code, or upload the document that came with the consignment.',
            'document.required_without' => 'Scan the QR on the challan, type the inward code, or upload the document that came with the consignment.',
            'document.mimes' => 'Upload the document as a PDF or a JPEG / PNG / WebP photo.',
            'document.max' => 'The file is larger than 15 MB. Scan at a lower resolution.',
        ];
    }
}
