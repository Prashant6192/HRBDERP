<?php

declare(strict_types=1);

namespace App\Http\Requests\Formulation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class PreviewFormulaImportRequest extends FormRequest
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
            'workbook' => ['required', File::types(['xlsx', 'xls'])->max(10 * 1024)],
            'assume_water_qs' => ['sometimes', 'boolean'],
            'activate' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'workbook.required' => 'Choose an Excel workbook to import.',
            'workbook.mimes' => 'The file must be an Excel workbook (.xlsx).',
        ];
    }
}
