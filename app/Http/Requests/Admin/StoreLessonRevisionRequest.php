<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreLessonRevisionRequest extends FormRequest
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
                'required',
                'uuid',
                'exists:topics,id',
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
        ];
    }
}
