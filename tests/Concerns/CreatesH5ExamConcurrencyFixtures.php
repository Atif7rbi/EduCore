<?php

namespace Tests\Concerns;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Exam\BuildExamGeneration;
use App\Application\TeacherAuthoring\ManageTeacherPracticeExamAuthoring;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesH5ExamConcurrencyFixtures
{
    /** @return array<string, mixed> */
    private function h5ExamFixture(int $templateVersionCount = 1, bool $withLearner = false): array
    {
        $curriculum = $this->createOwnedCurriculumFixture('H5 R3 '.Str::uuid());
        $versionId = (string) Str::uuid();
        $templateId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();

        $teacherId = DB::table('teacher_subject_assignments')
            ->where('id', $curriculum->teacher_subject_assignment_id)
            ->value('teacher_id');
        $this->assertIsString($teacherId);

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculum->id,
            'version_number' => 1,
            'label' => 'h5-r3',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => 'H5 R3 topic '.$topicId,
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => 'H5 R3 skill '.$skillId,
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('skill_version_placements')->insert([
            'id' => $placementId,
            'skill_id' => $skillId,
            'curriculum_version_id' => $versionId,
            'created_at' => now(),
        ]);
        DB::table('exam_templates')->insert([
            'id' => $templateId,
            'curriculum_version_id' => $versionId,
            'name' => 'H5 R3 template '.$templateId,
            'description' => null,
            'status' => 'active',
            'published_version_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $templateVersions = [];
        for ($number = 1; $number <= $templateVersionCount; $number++) {
            $id = (string) Str::uuid();
            DB::table('exam_template_versions')->insert([
                'id' => $id,
                'exam_template_id' => $templateId,
                'curriculum_version_id' => $versionId,
                'version_number' => $number,
                'label' => 'v'.$number,
                'status' => 'draft',
                'rules_payload' => json_encode(['question_count' => 1, 'marker' => 'initial-'.$number], JSON_THROW_ON_ERROR),
                'rules_schema_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $templateVersions[] = $id;
        }

        $revision = $this->h5ReleasedAssessmentRevision($versionId, $topicId, $placementId);
        $fixture = [
            'actor_user_id' => $teacherId,
            'assignment_id' => $curriculum->teacher_subject_assignment_id,
            'curriculum_id' => $curriculum->id,
            'curriculum_version_id' => $versionId,
            'template_id' => $templateId,
            'template_versions' => $templateVersions,
            'revision_id' => $revision['revision_id'],
            'item_id' => $revision['item_id'],
        ];

        if (! $withLearner) {
            return $fixture;
        }

        $learnerUserId = (string) Str::uuid();
        $learnerProfileId = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $learnerUserId,
            'name' => 'H5 R3 learner '.$learnerUserId,
            'email' => 'h5-r3-'.$learnerUserId.'@example.test',
            'password' => 'not-used',
            'status' => 'active',
            'role' => 'student',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('learner_profiles')->insert([
            'id' => $learnerProfileId,
            'user_id' => $learnerUserId,
            'created_at' => now(),
        ]);
        $enrollment = app(RequestStudentEnrollment::class)->execute(
            $learnerUserId,
            $learnerProfileId,
            $curriculum->teacher_subject_assignment_id,
            (string) Str::uuid(),
            'H5 R3 exam fixture',
        );
        app(AcceptStudentEnrollment::class)->execute(
            $teacherId,
            $enrollment->id,
            (string) Str::uuid(),
            'H5 R3 exam fixture',
        );

        return $fixture + [
            'learner_user_id' => $learnerUserId,
            'learner_profile_id' => $learnerProfileId,
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function h5PublishTemplateVersion(array $fixture, string $templateVersionId): void
    {
        DB::table('exam_template_versions')->where('id', $templateVersionId)->update([
            'status' => 'published',
            'updated_at' => now(),
        ]);
        DB::table('exam_templates')->where('id', $fixture['template_id'])->update([
            'published_version_id' => $templateVersionId,
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $fixture */
    private function h5BuildGeneration(array $fixture, string $templateVersionId, string $seed): string
    {
        $authoring = app(ManageTeacherPracticeExamAuthoring::class);
        $ids = [$fixture['revision_id']];
        $items = $authoring->generationItems(
            $fixture['actor_user_id'], $fixture['assignment_id'], $fixture['curriculum_id'],
            $fixture['curriculum_version_id'], $fixture['template_id'], $templateVersionId, $ids,
        );
        $generation = app(BuildExamGeneration::class)->execute(
            $templateVersionId,
            'h5-r3-generator',
            $seed,
            $items,
            fn (): string => $authoring->lockGenerationAuthority(
                $fixture['actor_user_id'], $fixture['assignment_id'], $fixture['curriculum_id'],
                $fixture['curriculum_version_id'], $fixture['template_id'], $templateVersionId, $ids,
            ),
        );

        return $generation->id;
    }

    /** @return array{revision_id: string, item_id: string} */
    private function h5ReleasedAssessmentRevision(string $versionId, string $topicId, string $placementId): array
    {
        $itemId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();
        DB::table('assessment_items')->insert([
            'id' => $itemId,
            'curriculum_version_id' => $versionId,
            'item_type' => 'multiple_choice',
            'internal_label' => 'H5 R3 item '.$itemId,
            'status' => 'draft',
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assessment_item_revisions')->insert([
            'id' => $revisionId,
            'assessment_item_id' => $itemId,
            'curriculum_version_id' => $versionId,
            'revision_number' => 1,
            'primary_topic_id' => $topicId,
            'difficulty' => 'easy',
            'content_payload' => json_encode(['stem' => 'H5 R3'], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'scoring_payload' => json_encode(['correct' => true], JSON_THROW_ON_ERROR),
            'scoring_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);
        DB::table('assessment_item_revision_skills')->insert([
            'id' => (string) Str::uuid(),
            'assessment_item_revision_id' => $revisionId,
            'skill_version_placement_id' => $placementId,
            'curriculum_version_id' => $versionId,
            'role' => 'primary',
            'created_at' => now(),
        ]);
        app(ReleaseAssessmentItemRevision::class)->execute($revisionId);

        return ['revision_id' => $revisionId, 'item_id' => $itemId];
    }
}
