<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AssignTeacherSubjectRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'subject_id' => trim(
                (string) $this->input(
                    'subject_id',
                    ''
                )
            ),
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
            'subject_id' => [
                'required',
                'uuid',
            ],
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
            'status' => [
                'prohibited',
            ],
        ];
    }
}
