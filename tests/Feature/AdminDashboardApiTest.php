<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_dashboard_summary(): void
    {
        $this->actingAs(
            User::factory()->create([
                'role' => 'admin',
                'status' => 'active',
            ])
        );

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.counts.subjects', 0)
            ->assertJsonPath('data.counts.curricula', 0)
            ->assertJsonPath('data.counts.curriculum_versions', 0)
            ->assertJsonPath('data.counts.topics', 0)
            ->assertJsonPath('data.counts.lessons', 0)
            ->assertJsonPath('data.counts.skills', 0)
            ->assertJsonPath('data.counts.assessment_items', 0)
            ->assertJsonPath('data.counts.practice_activities', 0)
            ->assertJsonPath('data.counts.exam_templates', 0)
            ->assertJsonPath('data.counts.learners', 0)
            ->assertJsonPath(
                'data.readiness.published_curriculum_versions',
                0,
            )
            ->assertJsonPath('data.readiness.published_lessons', 0)
            ->assertJsonPath('data.readiness.active_practice_activities', 0)
            ->assertJsonPath('data.readiness.active_exam_templates', 0);
    }

    public function test_dashboard_rejects_guest(): void
    {
        $this->getJson('/api/admin/dashboard')
            ->assertStatus(401);
    }
}
