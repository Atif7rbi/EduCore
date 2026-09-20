<?php

namespace Tests\Feature;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\DeactivateStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Exam\BuildExamGeneration;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\TestCase;

class ExamReadApiTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    private ?LearnerProfile $authenticatedLearner = null;

    public function test_exam_discovery_requires_authentication(): void
    {
        $this->getJson('/api/exam-generations')
            ->assertStatus(401);
    }

    public function test_exam_discovery_filters_generations_without_effective_grant(): void
    {
        $this->authenticateLearner();

        $authorized =
            $this->createGeneration();

        $unauthorized =
            $this->createGeneration(
                authorizeCurrentLearner: false,
            );

        $ids = collect(
            $this->getJson('/api/exam-generations')
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $this->assertTrue(
            $ids->contains(
                $authorized['generation_id']
            )
        );

        $this->assertFalse(
            $ids->contains(
                $unauthorized['generation_id']
            )
        );
    }

    public function test_exam_discovery_hides_generation_after_enrollment_deactivation(): void
    {
        $learner =
            $this->authenticateLearner();

        $fixture =
            $this->createGeneration();

        $enrollment = DB::table(
            'student_enrollments'
        )
            ->where(
                'learner_profile_id',
                $learner->id,
            )
            ->where(
                'teacher_subject_assignment_id',
                $fixture['assignment_id'],
            )
            ->first();

        $this->assertNotNull($enrollment);

        $teacherId = DB::table(
            'teacher_subject_assignments'
        )
            ->where(
                'id',
                $fixture['assignment_id'],
            )
            ->value('teacher_id');

        $this->assertIsString($teacherId);

        app(
            DeactivateStudentEnrollment::class
        )->execute(
            actorUserId: $teacherId,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'F-C4B exam discovery enrollment revocation.',
        );

        $ids = collect(
            $this->getJson('/api/exam-generations')
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $this->assertFalse(
            $ids->contains(
                $fixture['generation_id']
            )
        );
    }

    public function test_exam_discovery_hides_generation_when_assignment_is_inactive(): void
    {
        $this->authenticateLearner();

        $fixture =
            $this->createGeneration();

        $adminId = User::query()
            ->where('role', 'admin')
            ->where('status', 'active')
            ->value('id');

        $this->assertIsString($adminId);

        app(
            DeactivateTeacherSubjectAssignment::class
        )->execute(
            actorUserId: $adminId,
            assignmentId: $fixture['assignment_id'],
            operationId: (string) Str::uuid(),
            reason: 'F-C4B exam discovery assignment revocation.',
        );

        $ids = collect(
            $this->getJson('/api/exam-generations')
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $this->assertFalse(
            $ids->contains(
                $fixture['generation_id']
            )
        );
    }

    private function authenticateLearner(): LearnerProfile
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $learner = LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        $this->authenticatedLearner =
            $learner;

        $this->actingAs($user);

        return $learner;
    }

    /**
     * @return array{
     *     generation_id: string,
     *     curriculum_version_id: string,
     *     assignment_id: string
     * }
     */
    private function createGeneration(
        bool $authorizeCurrentLearner = true,
    ): array {
        $versionId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();
        $itemId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();
        $templateId = (string) Str::uuid();
        $templateVersionId =
            (string) Str::uuid();

        $curriculum =
            $this->createOwnedCurriculumFixture(
                'F-C4B Exam Curriculum '.Str::uuid()
            );

        $assignmentId =
            $curriculum
                ->teacher_subject_assignment_id;

        $this->assertIsString(
            $assignmentId
        );

        if (
            $authorizeCurrentLearner
            && $this->authenticatedLearner !== null
        ) {
            $this->activateEnrollment(
                $this->authenticatedLearner,
                $assignmentId,
            );
        }

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculum->id,
            'version_number' => 1,
            'label' => 'v1',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => "F-C4B Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => "F-C4B Skill {$skillId}",
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'skill_version_placements'
        )->insert([
            'id' => $placementId,
            'skill_id' => $skillId,
            'curriculum_version_id' => $versionId,
            'created_at' => now(),
        ]);

        DB::table('assessment_items')->insert([
            'id' => $itemId,
            'curriculum_version_id' => $versionId,
            'item_type' => 'multiple_choice',
            'internal_label' => "F-C4B Item {$itemId}",
            'status' => 'draft',
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'assessment_item_revisions'
        )->insert([
            'id' => $revisionId,
            'assessment_item_id' => $itemId,
            'curriculum_version_id' => $versionId,
            'revision_number' => 1,
            'primary_topic_id' => $topicId,
            'difficulty' => 'easy',
            'content_payload' => json_encode([
                'stem' => '2 + 2 = ?',
                'options' => [3, 4, 5, 6],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'scoring_payload' => json_encode([
                'correct_option' => 1,
            ], JSON_THROW_ON_ERROR),
            'scoring_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        DB::table(
            'assessment_item_revision_skills'
        )->insert([
            'id' => (string) Str::uuid(),
            'assessment_item_revision_id' => $revisionId,
            'skill_version_placement_id' => $placementId,
            'curriculum_version_id' => $versionId,
            'role' => 'primary',
            'created_at' => now(),
        ]);

        app(
            ReleaseAssessmentItemRevision::class
        )->execute($revisionId);

        DB::table('exam_templates')->insert([
            'id' => $templateId,
            'curriculum_version_id' => $versionId,
            'name' => "F-C4B Template {$templateId}",
            'description' => null,
            'status' => 'active',
            'published_version_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'exam_template_versions'
        )->insert([
            'id' => $templateVersionId,
            'exam_template_id' => $templateId,
            'curriculum_version_id' => $versionId,
            'version_number' => 1,
            'label' => 'v1',
            'status' => 'draft',
            'rules_payload' => json_encode([
                'question_count' => 1,
            ], JSON_THROW_ON_ERROR),
            'rules_schema_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'exam_template_versions'
        )
            ->where(
                'id',
                $templateVersionId,
            )
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $generation = app(
            BuildExamGeneration::class
        )->execute(
            $templateVersionId,
            'generator-v1',
            'f-c4b-'.Str::uuid(),
            [
                [
                    'assessment_item_revision_id' => $revisionId,
                    'assessment_item_id' => $itemId,
                ],
            ],
        );

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        return [
            'generation_id' => $generation->id,
            'curriculum_version_id' => $versionId,
            'assignment_id' => $assignmentId,
        ];
    }

    private function activateEnrollment(
        LearnerProfile $learner,
        string $assignmentId,
    ): void {
        $enrollment = app(
            RequestStudentEnrollment::class
        )->execute(
            actorUserId: $learner->user_id,
            learnerProfileId: $learner->id,
            assignmentId: $assignmentId,
            operationId: (string) Str::uuid(),
            reason: 'F-C4B exam discovery fixture.',
        );

        $teacherId = DB::table(
            'teacher_subject_assignments'
        )
            ->where('id', $assignmentId)
            ->value('teacher_id');

        $this->assertIsString($teacherId);

        app(
            AcceptStudentEnrollment::class
        )->execute(
            actorUserId: $teacherId,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'F-C4B exam discovery fixture acceptance.',
        );
    }
}
