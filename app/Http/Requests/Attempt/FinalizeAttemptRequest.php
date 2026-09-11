<?php

namespace App\Http\Requests\Attempt;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'final_status' => [
                'required',
                'string',
                'in:submitted,abandoned',
            ],
        ];
    }
}
