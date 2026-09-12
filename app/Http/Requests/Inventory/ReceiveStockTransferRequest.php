<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveStockTransferRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.written_off' => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The service wants the lines keyed by line id.
     *
     * @return array<int, array{quantity: string|null, written_off: string|null, notes: string|null}>
     */
    public function receivedLines(): array
    {
        $lines = [];

        foreach ($this->validated('lines') as $line) {
            $lines[(int) $line['line_id']] = [
                'quantity' => isset($line['quantity']) && $line['quantity'] !== '' ? (string) $line['quantity'] : '0',
                'written_off' => isset($line['written_off']) && $line['written_off'] !== '' ? (string) $line['written_off'] : '0',
                'notes' => $line['notes'] ?? null,
            ];
        }

        return $lines;
    }
}
