<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\TestCase;

class AdminDashboardApiTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use RefreshDatabase;

    public function test_admin_can_read_dashboard_summary(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin);

        /*
         * Prove LearnerProfile semantics with a non-zero
         * population. "learner" is not a User role.
         */
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        DB::table('learner_profiles')
            ->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $student->id,
                'created_at' => now(),
            ]);

        /*
         * Teacher-owned population through the authoritative
         * ownership construction path.
         */
        $teacherOwnedCurriculum =
            $this->createOwnedCurriculumFixture(
                'Dashboard Teacher Owned'
            );

        /*
         * Historical ownerless population.
         *
         * Current production writes correctly prohibit creation
         * without TeacherSubjectAssignment ownership, so this
         * fixture explicitly represents pre-ownership historical
         * data only.
         *
         * Guard the dedicated PostgreSQL test identity before
         * temporarily disabling the ownership trigger.
         */
        $identity = DB::selectOne(
            'SELECT '
            .'current_database() AS database_name, '
            .'current_user AS database_user'
        );

        $this->assertSame(
            'sewaellf_educore_test',
            $identity->database_name ?? null,
        );

        $this->assertSame(
            'sewaellf_educore_Admin',
            $identity->database_user ?? null,
        );

        $legacyOwnerlessCurriculumId =
            (string) Str::uuid();

        DB::statement(
            'ALTER TABLE curricula '
            .'DISABLE TRIGGER '
            .'trg_curricula_ownership_integrity'
        );

        try {
            DB::table('curricula')->insert([
                'id' => $legacyOwnerlessCurriculumId,
                'subject_id' => $this->canonicalSubjectId(),
                'education_stage_id' => null,
                'teacher_subject_assignment_id' => null,
                'name' => 'Dashboard Legacy Ownerless',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            DB::statement(
                'ALTER TABLE curricula '
                .'ENABLE TRIGGER '
                .'trg_curricula_ownership_integrity'
            );
        }

        /*
         * Create one published CurriculumVersion in each
         * ownership population so the split readiness metrics
         * cannot pass vacuously on zero rows.
         */
        $teacherOwnedVersionId =
            (string) Str::uuid();

        $legacyOwnerlessVersionId =
            (string) Str::uuid();

        DB::table('curriculum_versions')
            ->insert([
                [
                    'id' => $teacherOwnedVersionId,
                    'curriculum_id' => $teacherOwnedCurriculum->id,
                    'version_number' => 1,
                    'label' => 'Teacher Owned Published',
                    'status' => 'draft',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => $legacyOwnerlessVersionId,
                    'curriculum_id' => $legacyOwnerlessCurriculumId,
                    'version_number' => 1,
                    'label' => 'Legacy Ownerless Published',
                    'status' => 'draft',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

        DB::table('curriculum_versions')
            ->whereIn(
                'id',
                [
                    $teacherOwnedVersionId,
                    $legacyOwnerlessVersionId,
                ],
            )
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        /*
         * Explicit fixture truth before exercising the API.
         * These assertions prove the dashboard populations are
         * genuinely non-zero and ownership-separated.
         */
        $this->assertSame(
            1,
            DB::table('learner_profiles')
                ->count(),
        );

        $this->assertSame(
            1,
            DB::table('curricula')
                ->whereNotNull(
                    'teacher_subject_assignment_id'
                )
                ->count(),
        );

        $this->assertSame(
            1,
            DB::table('curricula')
                ->whereNull(
                    'teacher_subject_assignment_id'
                )
                ->count(),
        );

        $this->assertSame(
            1,
            DB::table(
                'curriculum_versions as version'
            )
                ->join(
                    'curricula as curriculum',
                    'curriculum.id',
                    '=',
                    'version.curriculum_id',
                )
                ->where(
                    'version.status',
                    'published',
                )
                ->whereNotNull(
                    'curriculum.teacher_subject_assignment_id'
                )
                ->count(),
        );

        $this->assertSame(
            1,
            DB::table(
                'curriculum_versions as version'
            )
                ->join(
                    'curricula as curriculum',
                    'curriculum.id',
                    '=',
                    'version.curriculum_id',
                )
                ->where(
                    'version.status',
                    'published',
                )
                ->whereNull(
                    'curriculum.teacher_subject_assignment_id'
                )
                ->count(),
        );

        $expectedCounts = [
            'subjects' => DB::table('subjects')->count(),

            'curricula' => DB::table('curricula')->count(),

            'teacher_owned_curricula' => DB::table('curricula')
                ->whereNotNull(
                    'teacher_subject_assignment_id'
                )
                ->count(),

            'legacy_ownerless_curricula' => DB::table('curricula')
                ->whereNull(
                    'teacher_subject_assignment_id'
                )
                ->count(),

            'curriculum_versions' => DB::table(
                'curriculum_versions'
            )->count(),

            'topics' => DB::table('topics')->count(),

            'lessons' => DB::table('lessons')->count(),

            'skills' => DB::table('skills')->count(),

            'assessment_items' => DB::table(
                'assessment_items'
            )->count(),

            'practice_activities' => DB::table(
                'practice_activities'
            )->count(),

            'exam_templates' => DB::table(
                'exam_templates'
            )->count(),

            /*
             * Canonical semantics:
             * dashboard "learners" means LearnerProfile.
             */
            'learners' => DB::table(
                'learner_profiles'
            )->count(),
        ];

        $expectedReadiness = [
            'published_curriculum_versions' => DB::table(
                'curriculum_versions'
            )
                ->where(
                    'status',
                    'published',
                )
                ->count(),

            'published_teacher_owned_curriculum_versions' => DB::table(
                'curriculum_versions as version'
            )
                ->join(
                    'curricula as curriculum',
                    'curriculum.id',
                    '=',
                    'version.curriculum_id',
                )
                ->where(
                    'version.status',
                    'published',
                )
                ->whereNotNull(
                    'curriculum.teacher_subject_assignment_id'
                )
                ->count(),

            'published_legacy_ownerless_curriculum_versions' => DB::table(
                'curriculum_versions as version'
            )
                ->join(
                    'curricula as curriculum',
                    'curriculum.id',
                    '=',
                    'version.curriculum_id',
                )
                ->where(
                    'version.status',
                    'published',
                )
                ->whereNull(
                    'curriculum.teacher_subject_assignment_id'
                )
                ->count(),

            'published_lessons' => DB::table('lessons')
                ->where(
                    'status',
                    'published',
                )
                ->count(),

            'active_practice_activities' => DB::table(
                'practice_activities'
            )
                ->where(
                    'status',
                    'active',
                )
                ->count(),

            'active_exam_templates' => DB::table(
                'exam_templates'
            )
                ->where(
                    'status',
                    'active',
                )
                ->count(),
        ];

        $response =
            $this->getJson(
                '/api/admin/dashboard'
            )
                ->assertOk();

        foreach (
            $expectedCounts as $key => $value
        ) {
            $response->assertJsonPath(
                "data.counts.{$key}",
                $value,
            );
        }

        foreach (
            $expectedReadiness as $key => $value
        ) {
            $response->assertJsonPath(
                "data.readiness.{$key}",
                $value,
            );
        }

        /*
         * PG-PR-001 closure assertions.
         *
         * Keep these explicit instead of relying only on
         * mirrored query-derived expectations.
         */
        $response
            ->assertJsonPath(
                'data.counts.learners',
                1,
            )
            ->assertJsonPath(
                'data.counts.teacher_owned_curricula',
                1,
            )
            ->assertJsonPath(
                'data.counts.legacy_ownerless_curricula',
                1,
            )
            ->assertJsonPath(
                'data.counts.curriculum_versions',
                2,
            )
            ->assertJsonPath(
                'data.readiness.published_curriculum_versions',
                2,
            )
            ->assertJsonPath(
                'data.readiness.published_teacher_owned_curriculum_versions',
                1,
            )
            ->assertJsonPath(
                'data.readiness.published_legacy_ownerless_curriculum_versions',
                1,
            );
    }

    public function test_dashboard_rejects_guest(): void
    {
        $this->getJson(
            '/api/admin/dashboard'
        )
            ->assertStatus(401);
    }
}
