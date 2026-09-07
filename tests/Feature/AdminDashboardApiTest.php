<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $expectedCounts = [
            'subjects' => DB::table('subjects')->count(),
            'curricula' => DB::table('curricula')->count(),
            'curriculum_versions' => DB::table('curriculum_versions')->count(),
            'topics' => DB::table('topics')->count(),
            'lessons' => DB::table('lessons')->count(),
            'skills' => DB::table('skills')->count(),
            'assessment_items' => DB::table('assessment_items')->count(),
            'practice_activities' => DB::table('practice_activities')->count(),
            'exam_templates' => DB::table('exam_templates')->count(),
            'learners' => DB::table('users')->where('role', 'learner')->count(),
        ];

        $expectedReadiness = [
            'published_curriculum_versions' => DB::table('curriculum_versions')
                ->where('status', 'published')
                ->count(),
            'published_lessons' => DB::table('lessons')
                ->where('status', 'published')
                ->count(),
            'active_practice_activities' => DB::table('practice_activities')
                ->where('status', 'active')
                ->count(),
            'active_exam_templates' => DB::table('exam_templates')
                ->where('status', 'active')
                ->count(),
        ];

        $response = $this->getJson('/api/admin/dashboard')
            ->assertOk();

        foreach ($expectedCounts as $key => $value) {
            $response->assertJsonPath("data.counts.{$key}", $value);
        }

        foreach ($expectedReadiness as $key => $value) {
            $response->assertJsonPath("data.readiness.{$key}", $value);
        }
    }

    public function test_dashboard_rejects_guest(): void
    {
        $this->getJson('/api/admin/dashboard')
            ->assertStatus(401);
    }
}
