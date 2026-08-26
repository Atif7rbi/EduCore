<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssessmentItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_type' => [
                'required',
                'string',
                'max:255',
            ],
            'internal_label' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }
}
