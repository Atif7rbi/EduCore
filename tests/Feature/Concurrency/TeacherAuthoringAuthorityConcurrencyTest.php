<?php

namespace Tests\Feature\Concurrency;

use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Models\AssessmentItem;
use App\Models\AssessmentItemRevision;
use App\Models\AssessmentItemRevisionSkill;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\PracticeActivity;
use App\Models\Skill;
use App\Models\SkillVersionPlacement;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\Support\PostgresProcessBarrier;
use Tests\TestCase;

class TeacherAuthoringAuthorityConcurrencyTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    public function test_disabled_teacher_cannot_commit_stale_practice_mutation(): void
    {
        [$admin, $teacher, $assignment, $curriculum, $version] = $this->fixture();

        $this->assertRevocationFailsClosed(
            table: 'users',
            id: $teacher->id,
            values: ['status' => 'disabled'],
            teacher: $teacher,
            assignment: $assignment,
            curriculum: $curriculum,
            version: $version,
        );
    }

    public function test_inactive_assignment_cannot_commit_stale_practice_mutation(): void
    {
        [$admin, $teacher, $assignment, $curriculum, $version] = $this->fixture();

        $this->assertRevocationFailsClosed(
            table: 'teacher_subject_assignments',
            id: $assignment->id,
            values: ['status' => 'inactive'],
            deactivationActor: $admin->id,
            teacher: $teacher,
            assignment: $assignment,
            curriculum: $curriculum,
            version: $version,
        );
    }

    public function test_opposite_revision_input_orders_complete_without_deadlock(): void
    {
        [$admin, $teacher, $assignment, $curriculum, $version] = $this->fixture();
        $first = $this->releasedRevision($version);
        $second = $this->releasedRevision($version);
        $left = PracticeActivity::query()->create(['curriculum_version_id' => $version->id, 'lesson_id' => null, 'name' => 'H5 left', 'description' => null, 'status' => 'archived']);
        $right = PracticeActivity::query()->create(['curriculum_version_id' => $version->id, 'lesson_id' => null, 'name' => 'H5 right', 'description' => null, 'status' => 'archived']);
        $payload = fn (string $practice, array $ids): array => ['action' => 'add_practice_items', 'actor_user_id' => $teacher->id, 'assignment_id' => $assignment->id, 'curriculum_id' => $curriculum->id, 'curriculum_version_id' => $version->id, 'practice_id' => $practice, 'revision_ids' => $ids];
        $a = PostgresProcessBarrier::start($payload($left->id, [$first->id, $second->id]), 'phase_h5_concurrency_worker.php');
        $b = PostgresProcessBarrier::start($payload($right->id, [$second->id, $first->id]), 'phase_h5_concurrency_worker.php');
        try {
            $a->awaitReady();
            $b->awaitReady();
            $a->release();
            $b->release();
            $leftResult = $a->finish();
            $rightResult = $b->finish();
            $this->assertTrue($leftResult['ok'] ?? false);
            $this->assertArrayHasKey('failure_type', $leftResult);
            $this->assertNull($leftResult['failure_type']);
            $this->assertSame('add_practice_items', $leftResult['operation'] ?? null);
            $this->assertSame(0, $leftResult['_exit_code'] ?? null);
            $this->assertTrue($rightResult['ok'] ?? false);
            $this->assertArrayHasKey('failure_type', $rightResult);
            $this->assertNull($rightResult['failure_type']);
            $this->assertSame('add_practice_items', $rightResult['operation'] ?? null);
            $this->assertSame(0, $rightResult['_exit_code'] ?? null);
            $this->assertSame(2, DB::table('practice_activity_items')->where('practice_activity_id', $left->id)->count());
            $this->assertSame(2, DB::table('practice_activity_items')->where('practice_activity_id', $right->id)->count());
        } finally {
            $a->cleanup();
            $b->cleanup();
        }
    }

    private function releasedRevision(CurriculumVersion $version): AssessmentItemRevision
    {
        $item = AssessmentItem::query()->create(['curriculum_version_id' => $version->id, 'item_type' => 'multiple_choice', 'internal_label' => null, 'status' => 'draft', 'published_revision_id' => null]);
        $revision = AssessmentItemRevision::query()->create(['assessment_item_id' => $item->id, 'curriculum_version_id' => $version->id, 'revision_number' => 1, 'primary_topic_id' => null, 'difficulty' => 'easy', 'content_payload' => ['q' => 'h5'], 'content_schema_version' => 1, 'scoring_payload' => ['a' => 'h5'], 'scoring_schema_version' => 1, 'released_at' => null]);
        $skill = Skill::query()->create(['name' => 'H5 '.Str::random(8), 'description' => null]);
        $placement = SkillVersionPlacement::query()->create(['skill_id' => $skill->id, 'curriculum_version_id' => $version->id]);
        AssessmentItemRevisionSkill::query()->create(['assessment_item_revision_id' => $revision->id, 'skill_version_placement_id' => $placement->id, 'curriculum_version_id' => $version->id, 'role' => 'primary']);
        DB::table('assessment_item_revisions')->where('id', $revision->id)->update(['released_at' => now()]);

        return $revision->refresh();
    }

    private function assertRevocationFailsClosed(string $table, string $id, array $values, User $teacher, TeacherSubjectAssignment $assignment, Curriculum $curriculum, CurriculumVersion $version, ?string $deactivationActor = null): void
    {
        DB::beginTransaction();
        $barrier = null;

        try {
            DB::table($table)->where('id', $id)->lockForUpdate()->first();
            if ($table === 'teacher_subject_assignments') {
                app(DeactivateTeacherSubjectAssignment::class)->execute(
                    $deactivationActor,
                    $id,
                    (string) Str::uuid(),
                    'H5 concurrent authority revocation.',
                );
            } else {
                DB::table($table)->where('id', $id)->update($values);
            }

            $barrier = PostgresProcessBarrier::start([
                'action' => 'create_practice',
                'actor_user_id' => $teacher->id,
                'assignment_id' => $assignment->id,
                'curriculum_id' => $curriculum->id,
                'curriculum_version_id' => $version->id,
                'name' => 'H5 concurrent practice '.Str::uuid(),
            ], 'phase_h5_concurrency_worker.php');
            $ready = $barrier->awaitReady();
            $this->assertSame('sewaellf_educore_test', $ready['database']);
            $this->assertSame('sewaellf_educore_Admin', $ready['user']);
            $barrier->release();
            $barrier->awaitBlockedByCurrentConnection($ready['pid']);
            DB::commit();

            $result = $barrier->finish();
            $this->assertFalse($result['ok'] ?? true);
            $this->assertSame('create_practice', $result['operation'] ?? null);
            $this->assertSame('domain', $result['failure_type'] ?? null);
            $this->assertSame(0, $result['_exit_code'] ?? null);
            $this->assertArrayHasKey('result', $result);
            $this->assertNull($result['result']);
            $this->assertSame(ModelNotFoundException::class, $result['exception_class'] ?? null);
            $this->assertNotSame('', $result['message'] ?? '');
            $this->assertArrayHasKey('sqlstate', $result);
            $this->assertNull($result['sqlstate']);
            $this->assertSame(0, DB::table('practice_activities')->where('curriculum_version_id', $version->id)->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $barrier?->cleanup();
        }
    }

    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $assignment = app(AssignTeacherSubject::class)->execute(
            actorUserId: $admin->id,
            teacherUserId: $teacher->id,
            subjectId: $this->canonicalSubjectId('mathematics'),
            operationId: (string) Str::uuid(),
            reason: 'H5 authority concurrency fixture',
        );
        $curriculum = app(CreateOwnedCurriculum::class)->execute(
            actorUserId: $teacher->id,
            teacherSubjectAssignmentId: $assignment->id,
            name: 'H5 '.Str::random(8),
            educationStageId: null,
        );
        $version = CurriculumVersion::query()->create([
            'curriculum_id' => $curriculum->id,
            'version_number' => 1,
            'label' => 'H5',
            'status' => 'draft',
        ]);

        return [$admin, $teacher, $assignment, $curriculum, $version];
    }
}
