<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssessmentItemRevisionSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'skill_version_placement_id' => [
                'required',
                'uuid',
                'exists:skill_version_placements,id',
            ],
            'role' => [
                'required',
                'in:primary,supporting',
            ],
        ];
    }
}
