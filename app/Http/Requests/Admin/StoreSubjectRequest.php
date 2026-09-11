<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('subjects', 'name'),
            ],
            'code' => ['prohibited'],
            'icon_key' => ['prohibited'],
            'thumbnail_key' => ['prohibited'],
            'sort_order' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
