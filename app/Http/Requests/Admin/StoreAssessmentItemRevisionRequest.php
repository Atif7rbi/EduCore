<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssessmentItemRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'revision_number' => [
                'required',
                'integer',
                'min:1',
            ],
            'primary_topic_id' => [
                'nullable',
                'uuid',
                'exists:topics,id',
            ],
            'difficulty' => [
                'required',
                'in:easy,medium,hard',
            ],
            'content_payload' => [
                'required',
                'array',
            ],
            'content_schema_version' => [
                'required',
                'integer',
                'min:1',
            ],
            'scoring_payload' => [
                'required',
                'array',
            ],
            'scoring_schema_version' => [
                'required',
                'integer',
                'min:1',
            ],
        ];
    }
}
