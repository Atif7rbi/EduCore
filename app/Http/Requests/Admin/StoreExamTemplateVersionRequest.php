<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreExamTemplateVersionRequest extends FormRequest
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
                'nullable',
                'string',
                'max:255',
            ],
            'rules_payload' => [
                'required',
                'array',
            ],
            'rules_schema_version' => [
                'required',
                'integer',
                'min:1',
            ],
        ];
    }
}
