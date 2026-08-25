<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAuthorizationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_role_is_exposed_through_authorization_abstraction(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
        ]);

        $this->assertTrue($user->hasRole('student'));
        $this->assertTrue($user->isStudent());
        $this->assertFalse($user->isTeacher());
        $this->assertFalse($user->isAdmin());
    }

    public function test_teacher_role_is_exposed_through_authorization_abstraction(): void
    {
        $user = User::factory()->create([
            'role' => 'teacher',
        ]);

        $this->assertTrue($user->hasRole('teacher'));
        $this->assertTrue($user->isTeacher());
        $this->assertFalse($user->isStudent());
        $this->assertFalse($user->isAdmin());
    }

    public function test_admin_role_is_exposed_through_authorization_abstraction(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->isAdmin());
        $this->assertFalse($user->isStudent());
        $this->assertFalse($user->isTeacher());
    }

    public function test_active_status_is_exposed_through_authorization_abstraction(): void
    {
        $active = User::factory()->create([
            'status' => 'active',
        ]);

        $disabled = User::factory()->create([
            'status' => 'disabled',
        ]);

        $this->assertTrue($active->isActive());
        $this->assertFalse($disabled->isActive());
    }

    public function test_unknown_role_query_does_not_grant_access(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
        ]);

        $this->assertFalse($user->hasRole('owner'));
    }
}
