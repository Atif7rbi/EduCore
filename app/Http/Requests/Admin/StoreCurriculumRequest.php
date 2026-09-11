<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCurriculumRequest extends FormRequest
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
            ],
            'education_stage_id' => [
                'nullable',
                'uuid',
                Rule::exists(
                    'education_stages',
                    'id',
                )->where(
                    'status',
                    'active',
                ),
            ],
        ];
    }
}
