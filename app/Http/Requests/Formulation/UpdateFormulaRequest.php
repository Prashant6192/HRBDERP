<?php

declare(strict_types=1);

namespace App\Http\Requests\Formulation;

use App\Domain\MasterData\Enums\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A formula's details together with its open draft's recipe.
 */
class UpdateFormulaRequest extends FormRequest
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
