<?php

namespace App\Http\Requests\Analytics;

use Illuminate\Foundation\Http\FormRequest;

class SkillAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'evidence_scope_id' => [
                'required',
                'uuid',
                'exists:evidence_scopes,id',
            ],
        ];
    }
}
