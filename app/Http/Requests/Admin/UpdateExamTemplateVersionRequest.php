<?php

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateExamTemplateVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => [
                'nullable',
                'string',
                'max:255',
            ],
            'rules_payload' => [
                'present',
                'array',
                function (
                    string $attribute,
                    mixed $value,
                    Closure $fail,
                ): void {
                    if (
                        is_array($value)
                        && $value !== []
                        && array_is_list($value)
                    ) {
                        $fail(
                            'The rules payload must be a JSON object.'
                        );
                    }
                },
            ],
            'rules_schema_version' => [
                'required',
                'integer',
                'min:1',
            ],
        ];
    }
}
