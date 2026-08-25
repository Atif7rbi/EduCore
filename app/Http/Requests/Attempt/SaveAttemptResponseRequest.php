<?php

namespace App\Http\Requests\Attempt;

use Illuminate\Foundation\Http\FormRequest;

class SaveAttemptResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'response_payload' => [
                'nullable',
                'array',
            ],
            'time_spent_ms' => [
                'required',
                'integer',
                'min:0',
            ],
        ];
    }
}
