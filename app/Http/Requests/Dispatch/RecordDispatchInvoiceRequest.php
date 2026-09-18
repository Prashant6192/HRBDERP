<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispatch;

use Illuminate\Foundation\Http\FormRequest;

class RecordDispatchInvoiceRequest extends FormRequest
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
            'invoice_number' => ['required', 'string', 'max:64'],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'irn' => ['nullable', 'string', 'size:64', 'regex:/^[0-9a-fA-F]{64}$/'],
            'ack_number' => ['nullable', 'string', 'max:32', 'required_with:irn'],
            'ack_date' => ['nullable', 'date', 'required_with:irn'],
            'signed_qr' => ['nullable', 'string', 'max:8000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'invoice_number.required' => 'Give the invoice number the billing software raised.',
            'invoice_date.before_or_equal' => 'An invoice cannot be dated in the future.',
            'irn.size' => 'An IRN is exactly 64 characters, as the IRP returned it.',
            'irn.regex' => 'An IRN is a 64-character hexadecimal reference.',
            'ack_number.required_with' => 'The acknowledgement number comes with the IRN.',
            'ack_date.required_with' => 'The acknowledgement date comes with the IRN.',
        ];
    }
}
