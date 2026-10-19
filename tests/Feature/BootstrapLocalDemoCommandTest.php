<?php

namespace Tests\Feature;

use App\Application\Identity\RegisterStudent;
use App\Application\LocalDemo\BootstrapLocalDemo;
use App\Application\LocalDemo\LocalDemoEnvironmentGuard;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BootstrapLocalDemoCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Local-Demo-Only-2026!';

    public function test_environment_guard_rejects_non_local_environment(): void
    {
        $this->expectException(RuntimeException::class);

        app(LocalDemoEnvironmentGuard::class)->assertSafe();
    }

    public function test_application_service_cannot_bypass_the_environment_guard(): void
    {
        try {
            app(BootstrapLocalDemo::class)->execute(self::PASSWORD);
            $this->fail('The application service bypassed the local environment guard.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The local demo bootstrap environment is not safe.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
    }

    #[DataProvider('weakPasswords')]
    public function test_application_service_rejects_weak_passwords_before_any_write(string $password): void
    {
        $this->allowGuardCalls(1);

        try {
            app(BootstrapLocalDemo::class)->execute($password);
            $this->fail('The application service accepted a password outside the canonical policy.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The demo password does not meet local bootstrap requirements.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
        $this->assertDatabaseMissing('skills', ['name' => 'Local arithmetic fluency']);
        $this->assertDatabaseMissing('curricula', ['name' => 'Local Mathematics Demonstration']);
    }

    public function test_environment_guard_rejects_each_static_identity_mismatch(): void
    {
        $original = config('database');

        try {
            app()->detectEnvironment(fn (): string => 'local');

            foreach ([
                ['driver' => 'sqlite'],
                ['host' => 'localhost'],
                ['port' => 5433],
                ['database' => null],
                ['database' => 'educore_local_test'],
                ['username' => 'educore_local_test_role'],
                ['url' => 'postgresql://forbidden-override'],
            ] as $change) {
                config(['database.connections.pgsql' => array_merge(
                    $original['connections']['pgsql'],
                    [
                        'driver' => 'pgsql',
                        'host' => '127.0.0.1',
                        'port' => 5432,
                        'database' => LocalDemoEnvironmentGuard::DATABASE,
                        'username' => LocalDemoEnvironmentGuard::ROLE,
                        'url' => null,
                    ],
                    $change,
                )]);

                try {
                    app(LocalDemoEnvironmentGuard::class)->assertSafe();
                    $this->fail('An unsafe local demo database configuration was accepted.');
                } catch (RuntimeException $exception) {
                    $this->assertSame(
                        'The local demo bootstrap environment is not safe.',
                        $exception->getMessage(),
                    );
                }
            }
        } finally {
            config(['database' => $original]);
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_environment_guard_rejects_live_database_identity_mismatch(): void
    {
        DB::connection()->selectOne('SELECT 1');
        $original = config('database');

        try {
            app()->detectEnvironment(fn (): string => 'local');
            config([
                'database.connections.pgsql.host' => '127.0.0.1',
                'database.connections.pgsql.port' => 5432,
                'database.connections.pgsql.database' => LocalDemoEnvironmentGuard::DATABASE,
                'database.connections.pgsql.username' => LocalDemoEnvironmentGuard::ROLE,
                'database.connections.pgsql.url' => null,
            ]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('database identity is not safe');

            app(LocalDemoEnvironmentGuard::class)->assertSafe();
        } finally {
            config(['database' => $original]);
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_command_builds_the_canonical_demo_lifecycle_without_disclosing_the_password(): void
    {
        $this->allowGuardCalls(2);

        $this->runCommand()
            ->assertSuccessful()
            ->doesntExpectOutputToContain(self::PASSWORD);

        $this->assertDatabaseHas('users', [
            'email' => BootstrapLocalDemo::ADMIN_EMAIL,
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => BootstrapLocalDemo::TEACHER_EMAIL,
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => BootstrapLocalDemo::STUDENT_EMAIL,
            'role' => 'student',
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('learner_profiles', 1);
        $this->assertDatabaseHas('teacher_account_provisionings', [
            'teacher_user_id' => User::query()->where('email', BootstrapLocalDemo::TEACHER_EMAIL)->value('id'),
        ]);
        $this->assertNotNull(DB::table('teacher_account_provisionings')->value('setup_completed_at'));

        $teacherId = User::query()->where('email', BootstrapLocalDemo::TEACHER_EMAIL)->value('id');
        $studentId = User::query()->where('email', BootstrapLocalDemo::STUDENT_EMAIL)->value('id');
        $assignment = DB::table('teacher_subject_assignments')->sole();
        $learner = DB::table('learner_profiles')->where('user_id', $studentId)->sole();

        $this->assertSame($teacherId, $assignment->teacher_id);
        $this->assertSame(
            Subject::query()->where('code', 'mathematics')->value('id'),
            $assignment->subject_id,
        );
        $this->assertSame('active', $assignment->status);
        $this->assertDatabaseHas('teacher_subject_assignment_transitions', [
            'from_status' => null,
            'to_status' => 'active',
        ]);
        $enrollment = DB::table('student_enrollments')->sole();
        $this->assertSame($learner->id, $enrollment->learner_profile_id);
        $this->assertSame($assignment->id, $enrollment->teacher_subject_assignment_id);
        $this->assertSame('active', $enrollment->status);
        $this->assertDatabaseHas('student_enrollment_transitions', [
            'from_status' => null,
            'to_status' => 'pending',
            'outcome' => 'requested',
        ]);
        $this->assertDatabaseHas('student_enrollment_transitions', [
            'from_status' => 'pending',
            'to_status' => 'active',
            'outcome' => 'accepted',
        ]);

        $curriculum = DB::table('curricula')->where('name', 'Local Mathematics Demonstration')->sole();
        $this->assertSame($assignment->id, $curriculum->teacher_subject_assignment_id);
        $versionOne = DB::table('curriculum_versions')
            ->where('curriculum_id', $curriculum->id)
            ->where('version_number', 1)
            ->sole();
        $versionTwo = DB::table('curriculum_versions')
            ->where('curriculum_id', $curriculum->id)
            ->where('version_number', 2)
            ->sole();
        $this->assertSame('published', $versionOne->status);
        $this->assertSame('draft', $versionTwo->status);
        $this->assertDatabaseHas('curriculum_versions', [
            'version_number' => 1,
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('curriculum_versions', [
            'version_number' => 2,
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('skills', ['name' => 'Local arithmetic fluency']);
        $placement = DB::table('skill_version_placements')->sole();
        $this->assertSame($versionOne->id, $placement->curriculum_version_id);
        $this->assertDatabaseCount('skill_home_topics', 1);
        $lesson = DB::table('lessons')->sole();
        $this->assertSame($versionOne->id, $lesson->curriculum_version_id);
        $this->assertSame('published', $lesson->status);
        $this->assertNotNull(DB::table('lesson_revisions')->value('released_at'));
        $this->assertDatabaseCount('lesson_revision_skills', 1);
        $assessment = DB::table('assessment_items')->sole();
        $this->assertSame($versionOne->id, $assessment->curriculum_version_id);
        $this->assertSame('published', $assessment->status);
        $this->assertNotNull(DB::table('assessment_item_revisions')->value('released_at'));
        $this->assertDatabaseCount('assessment_item_revision_skills', 1);
        $practice = DB::table('practice_activities')->sole();
        $this->assertSame($versionOne->id, $practice->curriculum_version_id);
        $this->assertSame($lesson->id, $practice->lesson_id);
        $this->assertSame('active', $practice->status);
        $this->assertDatabaseCount('practice_activity_items', 1);
        $template = DB::table('exam_templates')->sole();
        $templateVersion = DB::table('exam_template_versions')->sole();
        $generation = DB::table('exam_generations')->sole();
        $this->assertSame($versionOne->id, $template->curriculum_version_id);
        $this->assertSame('active', $template->status);
        $this->assertSame($template->id, $templateVersion->exam_template_id);
        $this->assertSame('published', $templateVersion->status);
        $this->assertSame($templateVersion->id, $generation->exam_template_version_id);
        $this->assertDatabaseCount('exam_generation_items', 1);
    }

    public function test_command_rejects_an_existing_admin_before_creating_demo_identities(): void
    {
        User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->allowGuardCalls(2);

        $this->runCommand()
            ->assertFailed()
            ->doesntExpectOutputToContain(self::PASSWORD);

        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
    }

    public function test_command_rejects_an_existing_reserved_demo_identity(): void
    {
        app(RegisterStudent::class)->register([
            'name' => 'Existing local student',
            'email' => BootstrapLocalDemo::STUDENT_EMAIL,
            'password' => self::PASSWORD,
        ]);
        $this->allowGuardCalls(2);

        $this->runCommand()->assertFailed();

        $this->assertSame(1, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
        $this->assertSame(0, User::query()->where('role', 'admin')->count());
    }

    public function test_repeated_command_fails_without_changing_the_completed_demo_dataset(): void
    {
        $this->allowGuardCalls(4);
        $this->runCommand()->assertSuccessful();

        $counts = [
            'users' => DB::table('users')->count(),
            'curricula' => DB::table('curricula')->count(),
            'skills' => DB::table('skills')->count(),
            'exam_generations' => DB::table('exam_generations')->count(),
        ];

        $this->runCommand()->assertFailed();

        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }
    }

    public function test_failed_canonical_lifecycle_operation_rolls_back_the_entire_bootstrap(): void
    {
        Subject::query()->where('code', 'mathematics')->update(['status' => 'inactive']);
        $this->allowGuardCalls(2);

        $this->runCommand()->assertFailed();

        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
        $this->assertDatabaseMissing('skills', ['name' => 'Local arithmetic fluency']);
        $this->assertDatabaseMissing('curricula', ['name' => 'Local Mathematics Demonstration']);
    }

    private function allowGuardCalls(int $times): void
    {
        $this->mock(
            LocalDemoEnvironmentGuard::class,
            fn (MockInterface $mock) => $mock->shouldReceive('assertSafe')->times($times),
        );
    }

    private function runCommand()
    {
        return $this->artisan('educore:bootstrap-local-demo')
            ->expectsQuestion('Demo account password', self::PASSWORD)
            ->expectsQuestion('Confirm demo account password', self::PASSWORD);
    }

    /** @return array<string, array{string}> */
    public static function weakPasswords(): array
    {
        return [
            'lowercase only' => ['abcdefghijkl'],
            'missing uppercase' => ['lowercase123!'],
            'missing lowercase' => ['UPPERCASE123!'],
            'missing number' => ['StrongPassword!'],
            'missing symbol' => ['StrongPassword123'],
            'below minimum length' => ['Short1!aA'],
        ];
    }
}
