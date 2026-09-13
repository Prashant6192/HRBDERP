<?php

declare(strict_types=1);

namespace App\Http\Requests\Formulation;

use App\Domain\MasterData\Enums\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFormulaRequest extends FormRequest
{
    use FormulaLinesRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseLines();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'product_id' => [
                'nullable', 'integer',
                Rule::exists('items', 'id')->where(fn ($q) => $q->where('type', ItemType::FinishedGood->value)->whereNull('deleted_at')),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            // Whose recipe it is; a client-owned or joint formula names the client.
            'ownership' => ['nullable', Rule::in(['company', 'client', 'joint'])],
            'client_id' => [
                'nullable', 'integer', 'required_if:ownership,client', 'required_if:ownership,joint',
                Rule::exists('clients', 'id')->whereNull('deleted_at'),
            ],
        ] + $this->versionRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->versionMessages();
    }
}
