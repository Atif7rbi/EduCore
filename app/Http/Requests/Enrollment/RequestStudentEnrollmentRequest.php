<?php

namespace App\Http\Requests\Enrollment;

use Illuminate\Foundation\Http\FormRequest;

class RequestStudentEnrollmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'teacher_subject_assignment_id' => trim(
                (string) $this->input(
                    'teacher_subject_assignment_id',
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
            'teacher_subject_assignment_id' => [
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
            'learner_profile_id' => [
                'prohibited',
            ],
            'student_user_id' => [
                'prohibited',
            ],
            'teacher_id' => [
                'prohibited',
            ],
            'status' => [
                'prohibited',
            ],
            'outcome' => [
                'prohibited',
            ],
        ];
    }
}
