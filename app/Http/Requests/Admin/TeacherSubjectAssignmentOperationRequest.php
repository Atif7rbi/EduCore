<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TeacherSubjectAssignmentOperationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'operation_id' => trim(
                (string) $this->input(
                    'operation_id',
                    ''
                )
            ),
            'reason' => trim(
                (string) $this->input(
                    'reason',
                    ''
                )
            ),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operation_id' => [
                'required',
                'uuid',
            ],
            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
            'actor_user_id' => [
                'prohibited',
            ],
            'teacher_id' => [
                'prohibited',
            ],
            'teacher_user_id' => [
                'prohibited',
            ],
            'subject_id' => [
                'prohibited',
            ],
            'status' => [
                'prohibited',
            ],
        ];
    }
}
