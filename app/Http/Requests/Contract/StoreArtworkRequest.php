<?php

declare(strict_types=1);

namespace App\Http\Requests\Contract;

use App\Domain\Contract\Models\ClientArtwork;
use App\Domain\MasterData\Enums\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreArtworkRequest extends FormRequest
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
            'product_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where(fn ($q) => $q->where('type', ItemType::FinishedGood->value)->whereNull('deleted_at'))],
            'kind' => ['required', Rule::in(ClientArtwork::KINDS)],
            'title' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:32'],
            'status' => ['nullable', Rule::in(['pending', 'approved'])],
            'approved_at' => ['nullable', 'date', 'required_if:status,approved'],
            'approved_by_name' => ['nullable', 'string', 'max:255'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:20480'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'approved_at.required_if' => 'Give the date the client approved it.',
            'document.mimes' => 'Upload the approval as a PDF or a JPEG / PNG / WebP image.',
        ];
    }
}
