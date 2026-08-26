<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePracticeActivityItemRequest extends FormRequest
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
                'exists:assessment_item_revisions,id',
            ],
            'display_order' => [
                'required',
                'integer',
                'min:0',
            ],
        ];
    }
}
