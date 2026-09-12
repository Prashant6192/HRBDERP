<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Domain\Warehousing\Enums\WarehouseType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreCategoryRequest extends FormRequest
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
        $id = $this->route('storeCategory')?->id;

        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-]+$/', Rule::unique('store_categories', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:120'],
            'badge' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
            'kind' => [$id ? 'sometimes' : 'required', new Enum(WarehouseType::class)],
            'icon' => ['nullable', 'string', 'max:48'],
            'color' => ['nullable', 'string', 'max:24'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'badge' => strtoupper(trim((string) $this->input('badge'))),
        ]);
    }
}
