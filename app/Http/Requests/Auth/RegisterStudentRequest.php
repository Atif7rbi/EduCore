<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class RegisterStudentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim(
                (string) $this->input('name', '')
            ),
            'email' => Str::lower(
                trim(
                    (string) $this->input('email', '')
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
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                function (
                    string $attribute,
                    mixed $value,
                    \Closure $fail,
                ): void {
                    $exists = User::query()
                        ->whereRaw(
                            'LOWER(email) = ?',
                            [
                                Str::lower(
                                    (string) $value
                                ),
                            ]
                        )
                        ->exists();

                    if ($exists) {
                        $fail(
                            'The email has already been taken.'
                        );
                    }
                },
            ],
            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(12)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'role' => [
                'prohibited',
            ],
            'status' => [
                'prohibited',
            ],
        ];
    }
}
