<?php

namespace App\Http\Requests\Practice;

use Illuminate\Foundation\Http\FormRequest;

class AddPracticeActivityItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assessment_item_revision_id' => [
                'required',
                'uuid',
            ],
            'assessment_item_id' => [
                'required',
                'uuid',
            ],
            'display_order' => [
                'required',
                'integer',
                'min:0',
            ],
        ];
    }
}
