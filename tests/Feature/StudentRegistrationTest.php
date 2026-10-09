<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_atomically_creates_active_student_and_learner_profile(): void
    {
        $response = $this->postJson(
            '/auth/register',
            [
                'name' => 'Student One',
                'email' => 'Student@One.Example',
                'password' => 'Strong#Pass123',
                'password_confirmation' => 'Strong#Pass123',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.user.email',
                'student@one.example',
            )
            ->assertJsonPath(
                'data.user.role',
                'student',
            )
            ->assertJsonPath(
                'data.user.status',
                'active',
            );

        $user = User::query()
            ->where(
                'email',
                'student@one.example',
            )
            ->firstOrFail();

        $this->assertTrue(
            Hash::check(
                'Strong#Pass123',
                $user->password,
            )
        );

        $this->assertNotNull(
            $user->learnerProfile
        );

        $this->assertSame(
            $user->learnerProfile->id,
            $response->json(
                'data.user.learner_profile_id'
            )
        );
    }

    public function test_public_registration_cannot_choose_role_or_status(): void
    {
        $this
            ->postJson(
                '/auth/register',
                [
                    'name' => 'Teacher Attempt',
                    'email' => 'teacher-attempt@example.test',
                    'password' => 'Strong#Pass123',
                    'password_confirmation' => 'Strong#Pass123',
                    'role' => 'teacher',
                    'status' => 'disabled',
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'role',
                'status',
            ]);

        $this->assertDatabaseMissing(
            'users',
            [
                'email' => 'teacher-attempt@example.test',
            ]
        );
    }

    public function test_public_registration_rejects_duplicate_normalized_email(): void
    {
        User::factory()->create([
            'email' => 'Student@Example.Test',
        ]);

        $this
            ->postJson(
                '/auth/register',
                [
                    'name' => 'Duplicate Student',
                    'email' => ' STUDENT@EXAMPLE.TEST ',
                    'password' => 'Strong#Pass123',
                    'password_confirmation' => 'Strong#Pass123',
                ]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'email',
            ]);
    }

    public function test_registration_rolls_back_user_when_learner_profile_creation_fails(): void
    {
        $learnerProfilesBefore = DB::table(
            'learner_profiles'
        )->count();

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION educore_test_fail_learner_profile_insert()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'forced learner profile failure'
        USING ERRCODE = '23514';
END;
$$;

CREATE TRIGGER trg_test_fail_learner_profile_insert
BEFORE INSERT ON learner_profiles
FOR EACH ROW
EXECUTE PROCEDURE educore_test_fail_learner_profile_insert();
SQL);

        $this
            ->postJson(
                '/auth/register',
                [
                    'name' => 'Atomic Student',
                    'email' => 'atomic-student@example.test',
                    'password' => 'Strong#Pass123',
                    'password_confirmation' => 'Strong#Pass123',
                ]
            )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'integrity_conflict'
            );

        $this->assertDatabaseMissing(
            'users',
            [
                'email' => 'atomic-student@example.test',
            ]
        );

        $this->assertSame(
            $learnerProfilesBefore,
            DB::table('learner_profiles')->count()
        );
    }
}
