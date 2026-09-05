<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchPropertiesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'city' => ['nullable', 'string', 'max:255'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'guests' => ['required', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    /**
     * @return array{check_in: string, check_out: string, guests: int, city: string|null}
     */
    public function filters(): array
    {
        return [
            'check_in' => $this->validated('check_in'),
            'check_out' => $this->validated('check_out'),
            'guests' => (int) $this->validated('guests'),
            'city' => $this->validated('city'),
        ];
    }
}
