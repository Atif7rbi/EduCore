<?php

namespace Tests\Feature;

use App\Application\Authorization\LockActiveLearnerCurriculumGrant;
use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\DeactivateStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Support\TransactionManager;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use App\Models\Curriculum;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\Support\PostgresProcessBarrier;
use Tests\TestCase;

class LockActiveLearnerCurriculumGrantTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $identity = DB::selectOne(
            'SELECT current_database() AS db, current_user AS usr'
        );

        if (
            ! is_object($identity)
            || ($identity->db ?? null)
                !== 'sewaellf_educore_test'
            || ($identity->usr ?? null)
                !== 'sewaellf_educore_Admin'
        ) {
            throw new \RuntimeException(
                'Refusing Phase F learner authorization tests '
                .'outside the dedicated PostgreSQL test identity.'
            );
        }
    }

    public function test_database_identity_contract(): void
    {
        $identity = DB::selectOne(
            'SELECT current_database() AS db, current_user AS usr'
        );

        $this->assertSame(
            'sewaellf_educore_test',
            $identity->db,
        );

        $this->assertSame(
            'sewaellf_educore_Admin',
            $identity->usr,
        );
    }

    public function test_active_matching_enrollment_authorizes_current_access(): void
    {
        [
            $learnerId,
            $versionId,
            $curriculum,
        ] = $this->createBaseFixture();

        $enrollment = $this->activateEnrollment(
            $learnerId,
            $curriculum,
        );

        $authorized = $this->authorize(
            $learnerId,
            $versionId,
        );

        $this->assertSame(
            $enrollment->id,
            $authorized->id,
        );

        $this->assertSame(
            'active',
            $authorized->status,
        );
    }

    public function test_missing_enrollment_rejects_current_access(): void
    {
        [
            $learnerId,
            $versionId,
        ] = $this->createBaseFixture();

        $this->expectException(
            ModelNotFoundException::class,
        );

        $this->authorize(
            $learnerId,
            $versionId,
        );
    }

    public function test_inactive_enrollment_rejects_current_access(): void
    {
        [
            $learnerId,
            $versionId,
            $curriculum,
        ] = $this->createBaseFixture();

        $enrollment = $this->activateEnrollment(
            $learnerId,
            $curriculum,
        );

        $teacherId = $this->teacherId(
            $curriculum,
        );

        app(
            DeactivateStudentEnrollment::class
        )->execute(
            actorUserId: $teacherId,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Phase F inactive enrollment test.',
        );

        $this->expectException(
            ModelNotFoundException::class,
        );

        $this->authorize(
            $learnerId,
            $versionId,
        );
    }

    public function test_inactive_assignment_rejects_current_access(): void
    {
        [
            $learnerId,
            $versionId,
            $curriculum,
        ] = $this->createBaseFixture();

        $this->activateEnrollment(
            $learnerId,
            $curriculum,
        );

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        app(
            DeactivateTeacherSubjectAssignment::class
        )->execute(
            actorUserId: $admin->id,
            assignmentId: $curriculum->teacher_subject_assignment_id,
            operationId: (string) Str::uuid(),
            reason: 'Phase F inactive assignment test.',
        );

        $this->expectException(
            ModelNotFoundException::class,
        );

        $this->authorize(
            $learnerId,
            $versionId,
        );
    }

    public function test_mismatched_authenticated_user_rejects_current_access(): void
    {
        [
            $learnerId,
            $versionId,
            $curriculum,
        ] = $this->createBaseFixture();

        $this->activateEnrollment(
            $learnerId,
            $curriculum,
        );

        $otherStudent = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->expectException(
            ModelNotFoundException::class,
        );

        $this->authorize(
            $learnerId,
            $versionId,
            $otherStudent->id,
        );
    }

    public function test_disabled_learner_owner_rejects_current_access(): void
    {
        [
            $learnerId,
            $versionId,
            $curriculum,
        ] = $this->createBaseFixture();

        $this->activateEnrollment(
            $learnerId,
            $curriculum,
        );

        $userId = DB::table('learner_profiles')
            ->where('id', $learnerId)
            ->value('user_id');

        $this->assertIsString($userId);

        DB::table('users')
            ->where('id', $userId)
            ->update([
                'status' => 'disabled',
                'updated_at' => now(),
            ]);

        $this->expectException(
            ModelNotFoundException::class,
        );

        $this->authorize(
            $learnerId,
            $versionId,
            $userId,
        );
    }

    public function test_non_student_learner_owner_rejects_current_access(): void
    {
        [
            $learnerId,
            $versionId,
            $curriculum,
        ] = $this->createBaseFixture();

        $this->activateEnrollment(
            $learnerId,
            $curriculum,
        );

        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        DB::table('learner_profiles')
            ->where('id', $learnerId)
            ->update([
                'user_id' => $teacher->id,
            ]);

        $this->assertSame(
            $teacher->id,
            DB::table('learner_profiles')
                ->where('id', $learnerId)
                ->value('user_id'),
        );

        $this->expectException(
            ModelNotFoundException::class,
        );

        $this->authorize(
            $learnerId,
            $versionId,
            $teacher->id,
        );
    }

    public function test_current_learner_grant_serializes_before_assignment_deactivation(): void
    {
        [
            $learnerId,
            $versionId,
            $curriculum,
        ] = $this->createBaseFixture();

        $enrollment =
            $this->activateEnrollment(
                $learnerId,
                $curriculum,
            );

        $authenticatedUserId =
            DB::table('learner_profiles')
                ->where('id', $learnerId)
                ->value('user_id');

        $assignmentId =
            $curriculum
                ->teacher_subject_assignment_id;

        $this->assertIsString(
            $authenticatedUserId
        );

        $this->assertIsString(
            $assignmentId
        );

        $admin =
            User::factory()->create([
                'role' => 'admin',
                'status' => 'active',
            ]);

        $barrier = null;

        DB::beginTransaction();

        try {
            $lockedEnrollment = app(
                LockActiveLearnerCurriculumGrant::class
            )->execute(
                $authenticatedUserId,
                $learnerId,
                $versionId,
            );

            $this->assertSame(
                $enrollment->id,
                $lockedEnrollment->id,
            );

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'deactivate_teacher_subject_assignment',
                    'actor_user_id' => $admin->id,
                    'assignment_id' => $assignmentId,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'PF-004 grant-wins assignment race.',
                ]);

            $ready =
                $barrier->awaitReady();

            $barrier->release();

            $wait =
                $barrier
                    ->awaitBlockedByCurrentConnection(
                        $ready['pid']
                    );

            $this->assertSame(
                'Lock',
                $wait['wait_event_type'],
            );

            $this->assertTrue(
                $wait['blocked_by_parent'],
            );

            $this->assertSame(
                $ready['pid'],
                $wait['child_pid'],
            );

            DB::commit();

            $result =
                $barrier->finish();

            $this->assertSame(
                'success',
                $result['result'] ?? null,
                "STDOUT:\n"
                .($result['_stdout'] ?? '')
                ."\nSTDERR:\n"
                .($result['_stderr'] ?? ''),
            );

            $this->assertSame(
                'inactive',
                $result['data']['status']
                    ?? null,
            );

            $this->assertSame(
                'inactive',
                DB::table(
                    'teacher_subject_assignments'
                )
                    ->where(
                        'id',
                        $assignmentId,
                    )
                    ->value('status'),
            );
        } finally {
            if (
                DB::transactionLevel() > 0
            ) {
                DB::rollBack();
            }

            $barrier?->cleanup();
        }
    }

    public function test_assignment_deactivation_serializes_before_current_learner_grant_and_grant_rechecks_status(): void
    {
        [
            $learnerId,
            $versionId,
            $curriculum,
        ] = $this->createBaseFixture();

        $this->activateEnrollment(
            $learnerId,
            $curriculum,
        );

        $authenticatedUserId =
            DB::table('learner_profiles')
                ->where('id', $learnerId)
                ->value('user_id');

        $assignmentId =
            $curriculum
                ->teacher_subject_assignment_id;

        $this->assertIsString(
            $authenticatedUserId
        );

        $this->assertIsString(
            $assignmentId
        );

        $admin =
            User::factory()->create([
                'role' => 'admin',
                'status' => 'active',
            ]);

        $barrier = null;

        DB::beginTransaction();

        try {
            $deactivated = app(
                DeactivateTeacherSubjectAssignment::class
            )->execute(
                actorUserId: $admin->id,
                assignmentId: $assignmentId,
                operationId: (string) Str::uuid(),
                reason: 'PF-004 deactivation-wins grant race.',
            );

            $this->assertSame(
                'inactive',
                $deactivated->status,
            );

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'lock_active_learner_curriculum_grant',
                    'authenticated_user_id' => $authenticatedUserId,
                    'learner_profile_id' => $learnerId,
                    'curriculum_version_id' => $versionId,
                ]);

            $ready =
                $barrier->awaitReady();

            $barrier->release();

            $wait =
                $barrier
                    ->awaitBlockedByCurrentConnection(
                        $ready['pid']
                    );

            $this->assertSame(
                'Lock',
                $wait['wait_event_type'],
            );

            $this->assertTrue(
                $wait['blocked_by_parent'],
            );

            $this->assertSame(
                $ready['pid'],
                $wait['child_pid'],
            );

            DB::commit();

            $result =
                $barrier->finish();

            $this->assertSame(
                'exception',
                $result['result'] ?? null,
                "STDOUT:\n"
                .($result['_stdout'] ?? '')
                ."\nSTDERR:\n"
                .($result['_stderr'] ?? ''),
            );

            $this->assertSame(
                ModelNotFoundException::class,
                $result['class'] ?? null,
            );

            $this->assertSame(
                'inactive',
                DB::table(
                    'teacher_subject_assignments'
                )
                    ->where(
                        'id',
                        $assignmentId,
                    )
                    ->value('status'),
            );
        } finally {
            if (
                DB::transactionLevel() > 0
            ) {
                DB::rollBack();
            }

            $barrier?->cleanup();
        }
    }

    private function authorize(
        string $learnerId,
        string $versionId,
        ?string $authenticatedUserId = null,
    ) {
        $authenticatedUserId ??=
            DB::table('learner_profiles')
                ->where('id', $learnerId)
                ->value('user_id');

        $this->assertIsString(
            $authenticatedUserId
        );

        $transactions = new TransactionManager(
            new PostgresExceptionTranslator,
        );

        return $transactions->run(
            fn () => app(
                LockActiveLearnerCurriculumGrant::class
            )->execute(
                $authenticatedUserId,
                $learnerId,
                $versionId,
            )
        );
    }

    /**
     * @return array{string, string, Curriculum}
     */
    private function createBaseFixture(): array
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $learnerId = (string) Str::uuid();

        DB::table('learner_profiles')->insert([
            'id' => $learnerId,
            'user_id' => $student->id,
            'created_at' => now(),
        ]);

        $curriculum =
            $this->createOwnedCurriculumFixture(
                'Phase F Curriculum '.Str::uuid(),
            );

        $versionId = (string) Str::uuid();

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculum->id,
            'version_number' => 1,
            'label' => 'v1',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            $learnerId,
            $versionId,
            $curriculum,
        ];
    }

    private function activateEnrollment(
        string $learnerId,
        Curriculum $curriculum,
    ) {
        $studentUserId = DB::table(
            'learner_profiles'
        )
            ->where('id', $learnerId)
            ->value('user_id');

        $assignmentId =
            $curriculum->teacher_subject_assignment_id;

        $teacherId = $this->teacherId(
            $curriculum,
        );

        $enrollment = app(
            RequestStudentEnrollment::class
        )->execute(
            actorUserId: $studentUserId,
            learnerProfileId: $learnerId,
            assignmentId: $assignmentId,
            operationId: (string) Str::uuid(),
            reason: 'Phase F current access request.',
        );

        return app(
            AcceptStudentEnrollment::class
        )->execute(
            actorUserId: $teacherId,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Phase F current access acceptance.',
        );
    }

    private function teacherId(
        Curriculum $curriculum,
    ): string {
        $teacherId =
            TeacherSubjectAssignment::query()
                ->whereKey(
                    $curriculum
                        ->teacher_subject_assignment_id,
                )
                ->value('teacher_id');

        $this->assertIsString($teacherId);

        return $teacherId;
    }
}
