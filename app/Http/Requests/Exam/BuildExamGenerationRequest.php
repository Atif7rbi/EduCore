<?php

namespace App\Http\Requests\Exam;

use Illuminate\Foundation\Http\FormRequest;

class BuildExamGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'generator_version' => [
                'required',
                'string',
                'max:255',
            ],
            'seed' => [
                'required',
                'string',
                'max:255',
            ],
            'items' => [
                'required',
                'array',
                'min:1',
            ],
            'items.*.assessment_item_revision_id' => [
                'required',
                'uuid',
            ],
            'items.*.assessment_item_id' => [
                'required',
                'uuid',
            ],
        ];
    }
}
