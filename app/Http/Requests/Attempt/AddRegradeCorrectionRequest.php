<?php

namespace App\Http\Requests\Attempt;

use Illuminate\Foundation\Http\FormRequest;

class AddRegradeCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'corrected_is_correct' => [
                'required',
                'boolean',
            ],
            'reason' => [
                'required',
                'string',
                'max:2000',
            ],
        ];
    }
}
