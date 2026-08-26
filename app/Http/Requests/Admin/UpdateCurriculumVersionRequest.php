<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCurriculumVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version_number' => [
                'required',
                'integer',
                'min:1',
            ],
            'label' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }
}
