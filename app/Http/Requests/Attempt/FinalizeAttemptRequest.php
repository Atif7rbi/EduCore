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
            'items' => [
                'required',
                'array',
            ],
            'items.*.attempt_item_id' => [
                'required',
                'uuid',
            ],
            'items.*.original_is_correct' => [
                'present',
                'nullable',
                'boolean',
            ],
        ];
    }
}
