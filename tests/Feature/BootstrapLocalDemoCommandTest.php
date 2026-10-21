<?php

namespace Tests\Feature;

use App\Application\Identity\RegisterStudent;
use App\Application\LocalDemo\BootstrapLocalDemo;
use App\Application\LocalDemo\LocalDemoEnvironmentGuard;
use App\Application\Support\TransactionManager;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BootstrapLocalDemoCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const ADMIN_PASSWORD = 'Local-Admin-Only-2026!';

    private const TEACHER_PASSWORD = 'Local-Teacher-Only-2026!';

    private const STUDENT_PASSWORD = 'Local-Student-Only-2026!';

    private string $credentialDirectory;

    private ?string $symlinkTarget = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentialDirectory = sys_get_temp_dir()
            .'/educore-local-demo-credentials-'.bin2hex(random_bytes(12));
        putenv('EDUCORE_LOCAL_DEMO_TEST_CREDENTIAL_DIRECTORY='.$this->credentialDirectory);
        putenv(BootstrapLocalDemo::TEST_ALLOW_NESTED_TRANSACTION.'=1');
    }

    protected function tearDown(): void
    {
        putenv('EDUCORE_LOCAL_DEMO_TEST_CREDENTIAL_DIRECTORY');
        putenv('EDUCORE_LOCAL_DEMO_TEST_FAULT');
        putenv(BootstrapLocalDemo::TEST_ALLOW_NESTED_TRANSACTION);

        if (is_link($this->credentialDirectory)) {
            unlink($this->credentialDirectory);
        } elseif (is_dir($this->credentialDirectory)) {
            File::deleteDirectory($this->credentialDirectory);
        }

        if ($this->symlinkTarget !== null && is_dir($this->symlinkTarget)) {
            File::deleteDirectory($this->symlinkTarget);
        }

        parent::tearDown();
    }

    public function test_environment_guard_rejects_non_local_environment(): void
    {
        $this->expectException(RuntimeException::class);

        app(LocalDemoEnvironmentGuard::class)->assertSafe();
    }

    public function test_application_service_cannot_bypass_the_environment_guard(): void
    {
        try {
            app(BootstrapLocalDemo::class)->execute(
                self::ADMIN_PASSWORD,
                self::TEACHER_PASSWORD,
                self::STUDENT_PASSWORD,
                ...$this->operationIds(),
            );
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
    public function test_application_service_rejects_weak_passwords_before_any_write(
        string $account,
        string $password,
    ): void {
        $this->allowGuardCalls(1);
        $passwords = $this->validPasswords();
        $passwords[$account] = $password;

        try {
            app(BootstrapLocalDemo::class)->execute(
                $passwords['admin'],
                $passwords['teacher'],
                $passwords['student'],
                ...$this->operationIds(),
            );
            $this->fail('The application service accepted a password outside the canonical policy.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The demo credentials do not meet local bootstrap requirements.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString($password, $exception->getMessage());
        }

        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
        $this->assertDatabaseMissing('skills', ['name' => 'Local arithmetic fluency']);
        $this->assertDatabaseMissing('curricula', ['name' => 'Local Mathematics Demonstration']);
    }

    public function test_application_service_rejects_repeated_passwords_before_any_write(): void
    {
        $this->allowGuardCalls(1);

        try {
            app(BootstrapLocalDemo::class)->execute(
                self::ADMIN_PASSWORD,
                self::ADMIN_PASSWORD,
                self::STUDENT_PASSWORD,
                ...$this->operationIds(),
            );
            $this->fail('The application service accepted shared demo credentials.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The demo credentials must be mutually distinct.', $exception->getMessage());
            $this->assertStringNotContainsString(self::ADMIN_PASSWORD, $exception->getMessage());
        }

        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
        $this->assertDatabaseMissing('skills', ['name' => 'Local arithmetic fluency']);
        $this->assertDatabaseMissing('curricula', ['name' => 'Local Mathematics Demonstration']);
    }

    public function test_application_service_rejects_invalid_operation_provenance_before_any_write(): void
    {
        $this->allowGuardCalls(1);

        try {
            app(BootstrapLocalDemo::class)->execute(
                self::ADMIN_PASSWORD,
                self::TEACHER_PASSWORD,
                self::STUDENT_PASSWORD,
                'not-a-uuid',
                ...array_slice($this->operationIds(), 1),
            );
            $this->fail('The application service accepted invalid operation provenance.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The demo bootstrap operation identity is invalid.', $exception->getMessage());
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
            ->doesntExpectOutputToContain(self::ADMIN_PASSWORD)
            ->doesntExpectOutputToContain(self::TEACHER_PASSWORD)
            ->doesntExpectOutputToContain(self::STUDENT_PASSWORD);

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
        $this->assertTrue(Hash::check(
            self::ADMIN_PASSWORD,
            (string) User::query()->where('email', BootstrapLocalDemo::ADMIN_EMAIL)->value('password'),
        ));
        $this->assertTrue(Hash::check(
            self::TEACHER_PASSWORD,
            (string) User::query()->where('email', BootstrapLocalDemo::TEACHER_EMAIL)->value('password'),
        ));
        $this->assertTrue(Hash::check(
            self::STUDENT_PASSWORD,
            (string) User::query()->where('email', BootstrapLocalDemo::STUDENT_EMAIL)->value('password'),
        ));
        $this->assertCredentialArtifact();
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
            ->doesntExpectOutputToContain(self::ADMIN_PASSWORD)
            ->doesntExpectOutputToContain(self::TEACHER_PASSWORD)
            ->doesntExpectOutputToContain(self::STUDENT_PASSWORD);

        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
        $this->assertCredentialArtifactAbsent();
    }

    public function test_command_rejects_an_existing_reserved_demo_identity(): void
    {
        app(RegisterStudent::class)->register([
            'name' => 'Existing local student',
            'email' => BootstrapLocalDemo::STUDENT_EMAIL,
            'password' => self::STUDENT_PASSWORD,
        ]);
        $this->allowGuardCalls(2);

        $this->runCommand()
            ->assertFailed()
            ->doesntExpectOutputToContain(self::ADMIN_PASSWORD)
            ->doesntExpectOutputToContain(self::TEACHER_PASSWORD)
            ->doesntExpectOutputToContain(self::STUDENT_PASSWORD);

        $this->assertSame(1, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
        $this->assertSame(0, User::query()->where('role', 'admin')->count());
        $this->assertCredentialArtifactAbsent();
    }

    public function test_repeated_command_fails_without_changing_the_completed_demo_dataset(): void
    {
        $this->allowGuardCalls(3);
        $this->runCommand()->assertSuccessful();

        $counts = [
            'users' => DB::table('users')->count(),
            'curricula' => DB::table('curricula')->count(),
            'skills' => DB::table('skills')->count(),
            'exam_generations' => DB::table('exam_generations')->count(),
        ];
        $credentialHash = hash_file('sha256', $this->credentialPath());

        $this->runCommand()->assertFailed();

        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }

        $this->assertSame($credentialHash, hash_file('sha256', $this->credentialPath()));
    }

    public function test_failed_canonical_lifecycle_operation_rolls_back_the_entire_bootstrap(): void
    {
        Subject::query()->where('code', 'mathematics')->update(['status' => 'inactive']);
        $this->allowGuardCalls(2);

        $this->runCommand()->assertFailed();

        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
        $this->assertDatabaseMissing('skills', ['name' => 'Local arithmetic fluency']);
        $this->assertDatabaseMissing('curricula', ['name' => 'Local Mathematics Demonstration']);
        $this->assertCredentialArtifactAbsent();
    }

    public function test_command_rejects_non_private_credential_directory_before_any_write(): void
    {
        mkdir($this->credentialDirectory, 0755);
        chmod($this->credentialDirectory, 0755);
        $this->allowGuardCalls(1);

        $this->runCommand()->assertFailed();

        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
        $this->assertFileDoesNotExist($this->credentialPath());
        $this->assertSame('0755', sprintf('%04o', fileperms($this->credentialDirectory) & 0777));
    }

    public function test_command_rejects_existing_credential_file_without_overwriting_it(): void
    {
        mkdir($this->credentialDirectory, 0700);
        file_put_contents($this->credentialPath(), 'operator-owned-existing-artifact');
        chmod($this->credentialPath(), 0600);
        $originalHash = hash_file('sha256', $this->credentialPath());
        $this->allowGuardCalls(1);

        $this->runCommand()->assertFailed();

        $this->assertSame($originalHash, hash_file('sha256', $this->credentialPath()));
        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
    }

    public function test_command_rejects_a_symlinked_credential_directory(): void
    {
        $this->symlinkTarget = sys_get_temp_dir()
            .'/educore-local-demo-symlink-target-'.bin2hex(random_bytes(12));
        mkdir($this->symlinkTarget, 0700);
        symlink($this->symlinkTarget, $this->credentialDirectory);
        $this->allowGuardCalls(1);

        $this->runCommand()->assertFailed();

        $this->assertTrue(is_link($this->credentialDirectory));
        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
        $this->assertSame([], array_values(array_diff(scandir($this->symlinkTarget), ['.', '..'])));
    }

    public function test_post_commit_finalization_failure_preserves_a_recoverable_pending_artifact(): void
    {
        putenv('EDUCORE_LOCAL_DEMO_TEST_FAULT=finalization');
        $this->allowGuardCalls(3);

        $this->runCommand()->assertFailed();

        $this->assertSame(3, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
        $this->assertFileExists($this->pendingCredentialPath());
        $this->assertFileDoesNotExist($this->credentialPath());
        $this->assertSame('0600', sprintf('%04o', fileperms($this->pendingCredentialPath()) & 0777));

        putenv('EDUCORE_LOCAL_DEMO_TEST_FAULT');
        $this->runCommand(['--recover' => true])->assertSuccessful();

        $this->assertCredentialArtifact();
        $this->assertSame(3, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
    }

    public function test_interruption_after_commit_is_recovered_without_a_second_bootstrap(): void
    {
        putenv('EDUCORE_LOCAL_DEMO_TEST_FAULT=after_commit');
        $this->allowGuardCalls(4);

        $this->runCommand()->assertFailed();
        $counts = [
            'users' => DB::table('users')->count(),
            'curricula' => DB::table('curricula')->count(),
            'skills' => DB::table('skills')->count(),
            'exam_generations' => DB::table('exam_generations')->count(),
        ];
        $this->assertFileExists($this->pendingCredentialPath());
        $this->assertFileDoesNotExist($this->credentialPath());

        putenv('EDUCORE_LOCAL_DEMO_TEST_FAULT');
        $this->runCommand(['--recover' => true])->assertSuccessful();
        $credentialHash = hash_file('sha256', $this->credentialPath());
        $this->runCommand(['--recover' => true])->assertSuccessful();

        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }

        $this->assertSame($credentialHash, hash_file('sha256', $this->credentialPath()));
        $this->assertCredentialArtifact();
    }

    public function test_failed_pending_cleanup_is_reported_and_recovery_refuses_uncommitted_state(): void
    {
        Subject::query()->where('code', 'mathematics')->update(['status' => 'inactive']);
        putenv('EDUCORE_LOCAL_DEMO_TEST_FAULT=cleanup');
        $this->allowGuardCalls(3);

        $this->runCommand()->assertFailed();

        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
        $this->assertFileExists($this->pendingCredentialPath());
        $this->assertFileDoesNotExist($this->credentialPath());

        putenv('EDUCORE_LOCAL_DEMO_TEST_FAULT');
        $this->runCommand(['--recover' => true])->assertFailed();

        $this->assertFileExists($this->pendingCredentialPath());
        $this->assertFileDoesNotExist($this->credentialPath());
        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
    }

    public function test_recovery_refuses_invalid_pending_provenance_without_discarding_it(): void
    {
        putenv('EDUCORE_LOCAL_DEMO_TEST_FAULT=after_commit');
        $this->allowGuardCalls(3);
        $this->runCommand()->assertFailed();

        $payload = json_decode(
            (string) file_get_contents($this->pendingCredentialPath()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $payload['operations']['assignment'] = (string) Str::uuid();
        file_put_contents(
            $this->pendingCredentialPath(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );
        chmod($this->pendingCredentialPath(), 0600);

        putenv('EDUCORE_LOCAL_DEMO_TEST_FAULT');
        $this->runCommand(['--recover' => true])->assertFailed();

        $this->assertFileExists($this->pendingCredentialPath());
        $this->assertFileDoesNotExist($this->credentialPath());
        $this->assertSame(3, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
    }

    public function test_recovery_refuses_a_pending_artifact_when_committed_identities_are_missing(): void
    {
        Subject::query()->where('code', 'mathematics')->update(['status' => 'inactive']);
        putenv('EDUCORE_LOCAL_DEMO_TEST_FAULT=cleanup');
        $this->allowGuardCalls(3);
        $this->runCommand()->assertFailed();

        putenv('EDUCORE_LOCAL_DEMO_TEST_FAULT');
        $this->runCommand(['--recover' => true])->assertFailed();

        $this->assertFileExists($this->pendingCredentialPath());
        $this->assertFileDoesNotExist($this->credentialPath());
        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
    }

    public function test_existing_outer_transaction_is_rejected_before_credential_staging_or_database_mutation(): void
    {
        putenv(BootstrapLocalDemo::TEST_ALLOW_NESTED_TRANSACTION);
        $this->allowGuardCalls(1);

        try {
            $this->artisan('educore:bootstrap-local-demo')->assertFailed();
        } finally {
            putenv(BootstrapLocalDemo::TEST_ALLOW_NESTED_TRANSACTION.'=1');
        }

        $this->assertCredentialArtifactAbsent();
        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
    }

    public function test_application_service_rejects_programmatic_invocation_inside_an_outer_transaction(): void
    {
        putenv(BootstrapLocalDemo::TEST_ALLOW_NESTED_TRANSACTION);
        $this->allowGuardCalls(1);

        try {
            try {
                app(BootstrapLocalDemo::class)->execute(
                    self::ADMIN_PASSWORD,
                    self::TEACHER_PASSWORD,
                    self::STUDENT_PASSWORD,
                    ...$this->operationIds(),
                );
                $this->fail('The application service accepted a nested bootstrap invocation.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Local demo bootstrap requires an outermost database transaction.',
                    $exception->getMessage(),
                );
            }
        } finally {
            putenv(BootstrapLocalDemo::TEST_ALLOW_NESTED_TRANSACTION.'=1');
        }

        $this->assertSame(0, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
    }

    public function test_indeterminate_commit_preserves_pending_credentials_until_verified_recovery(): void
    {
        putenv(BootstrapLocalDemo::TEST_FAULT.'=ambiguous_commit');
        $this->allowGuardCalls(3);

        $this->runCommand()
            ->assertFailed()
            ->doesntExpectOutputToContain(self::ADMIN_PASSWORD)
            ->doesntExpectOutputToContain(self::TEACHER_PASSWORD)
            ->doesntExpectOutputToContain(self::STUDENT_PASSWORD);

        $this->assertSame(3, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
        $this->assertFileExists($this->pendingCredentialPath());
        $this->assertFileDoesNotExist($this->credentialPath());

        putenv(BootstrapLocalDemo::TEST_FAULT);
        $this->runCommand(['--recover' => true])->assertSuccessful();

        $this->assertCredentialArtifact();
        $this->assertSame(3, User::query()->whereIn('email', BootstrapLocalDemo::EMAILS)->count());
    }

    public function test_recovery_reconciles_pending_and_final_hard_links_without_reprovisioning(): void
    {
        putenv(BootstrapLocalDemo::TEST_FAULT.'=after_commit');
        $this->allowGuardCalls(3);

        $this->runCommand()->assertFailed();
        $this->assertTrue(link($this->pendingCredentialPath(), $this->credentialPath()));
        $this->assertSame(
            lstat($this->pendingCredentialPath())['ino'],
            lstat($this->credentialPath())['ino'],
        );
        $counts = [
            'users' => DB::table('users')->count(),
            'curricula' => DB::table('curricula')->count(),
            'skills' => DB::table('skills')->count(),
        ];

        putenv(BootstrapLocalDemo::TEST_FAULT);
        $this->runCommand(['--recover' => true])->assertSuccessful();

        $this->assertCredentialArtifact();
        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }
    }

    public function test_transaction_manager_performs_a_real_outermost_commit_on_an_isolated_fixture(): void
    {
        $originalDefault = DB::getDefaultConnection();
        $writerName = 'local_demo_real_commit_writer';
        $observerName = 'local_demo_real_commit_observer';
        $connection = config('database.connections.pgsql');
        $email = 'local-demo-commit-fixture-'.Str::uuid().'@educore.test';

        $this->assertIsArray($connection);
        $expectedDatabase = $connection['database'] ?? null;
        $expectedRole = $connection['username'] ?? null;

        $this->assertIsString($expectedDatabase);
        $this->assertMatchesRegularExpression('/(?:^|[_-])(test|testing)(?:[_-]|$)/i', $expectedDatabase);
        $this->assertIsString($expectedRole);
        $this->assertNotSame('', $expectedRole);
        config([
            "database.connections.{$writerName}" => $connection,
            "database.connections.{$observerName}" => $connection,
        ]);

        try {
            DB::setDefaultConnection($writerName);
            $writer = DB::connection($writerName);
            $observer = DB::connection($observerName);

            $this->assertSame(0, $writer->transactionLevel());
            $identity = $writer->selectOne(
                'SELECT current_database() AS database_name, current_user AS role_name, current_setting(\'TimeZone\') AS timezone',
            );
            $this->assertSame($expectedDatabase, $identity->database_name);
            $this->assertSame($expectedRole, $identity->role_name);
            $this->assertSame('UTC', $identity->timezone);
            $this->assertSame(0, $observer->table('password_reset_tokens')->where('email', $email)->count());

            app(TransactionManager::class)->run(function () use ($email): void {
                DB::table('password_reset_tokens')->insert([
                    'email' => $email,
                    'token' => hash('sha256', $email),
                    'created_at' => now(),
                ]);
            });

            $this->assertSame(0, $writer->transactionLevel());
            $this->assertSame(1, $observer->table('password_reset_tokens')->where('email', $email)->count());
        } finally {
            try {
                DB::connection($observerName)
                    ->table('password_reset_tokens')
                    ->where('email', $email)
                    ->delete();
            } finally {
                DB::setDefaultConnection($originalDefault);
                DB::purge($writerName);
                DB::purge($observerName);
                config([
                    "database.connections.{$writerName}" => null,
                    "database.connections.{$observerName}" => null,
                ]);
            }
        }

        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $email)->count());
    }

    private function allowGuardCalls(int $times): void
    {
        $this->mock(
            LocalDemoEnvironmentGuard::class,
            fn (MockInterface $mock) => $mock->shouldReceive('assertSafe')->times($times),
        );
    }

    /** @param array<string, mixed> $options */
    private function runCommand(array $options = [])
    {
        $command = $this->artisan('educore:bootstrap-local-demo', $options);

        if (($options['--recover'] ?? false) === true) {
            return $command;
        }

        return $command
            ->expectsQuestion('Admin demo account password', self::ADMIN_PASSWORD)
            ->expectsQuestion('Confirm Admin demo account password', self::ADMIN_PASSWORD)
            ->expectsQuestion('Teacher demo account password', self::TEACHER_PASSWORD)
            ->expectsQuestion('Confirm Teacher demo account password', self::TEACHER_PASSWORD)
            ->expectsQuestion('Student demo account password', self::STUDENT_PASSWORD)
            ->expectsQuestion('Confirm Student demo account password', self::STUDENT_PASSWORD);
    }

    /** @return array{admin: string, teacher: string, student: string} */
    private function validPasswords(): array
    {
        return [
            'admin' => self::ADMIN_PASSWORD,
            'teacher' => self::TEACHER_PASSWORD,
            'student' => self::STUDENT_PASSWORD,
        ];
    }

    private function credentialPath(): string
    {
        return $this->credentialDirectory.'/credentials.json';
    }

    private function pendingCredentialPath(): string
    {
        return $this->credentialDirectory.'/credentials.pending.json';
    }

    private function assertCredentialArtifact(): void
    {
        $this->assertDirectoryExists($this->credentialDirectory);
        $this->assertFileExists($this->credentialPath());
        $this->assertSame('0700', sprintf('%04o', fileperms($this->credentialDirectory) & 0777));
        $this->assertSame('0600', sprintf('%04o', fileperms($this->credentialPath()) & 0777));
        $this->assertFalse(is_link($this->credentialDirectory));
        $this->assertFalse(is_link($this->credentialPath()));

        $payload = json_decode((string) file_get_contents($this->credentialPath()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(BootstrapLocalDemo::ADMIN_EMAIL, $payload['accounts']['admin']['email']);
        $this->assertSame(BootstrapLocalDemo::TEACHER_EMAIL, $payload['accounts']['teacher']['email']);
        $this->assertSame(BootstrapLocalDemo::STUDENT_EMAIL, $payload['accounts']['student']['email']);
        $this->assertTrue(hash_equals(self::ADMIN_PASSWORD, $payload['accounts']['admin']['password']));
        $this->assertTrue(hash_equals(self::TEACHER_PASSWORD, $payload['accounts']['teacher']['password']));
        $this->assertTrue(hash_equals(self::STUDENT_PASSWORD, $payload['accounts']['student']['password']));
        $this->assertFileDoesNotExist($this->pendingCredentialPath());
    }

    private function assertCredentialArtifactAbsent(): void
    {
        $this->assertFileDoesNotExist($this->credentialPath());
        $this->assertFileDoesNotExist($this->pendingCredentialPath());
        $this->assertDirectoryDoesNotExist($this->credentialDirectory);
    }

    /** @return array{string, string, string} */
    private function operationIds(): array
    {
        return [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
    }

    /** @return array<string, array{string, string}> */
    public static function weakPasswords(): array
    {
        return [
            'admin lowercase only' => ['admin', 'abcdefghijkl'],
            'teacher missing uppercase' => ['teacher', 'lowercase123!'],
            'student missing lowercase' => ['student', 'UPPERCASE123!'],
            'admin missing number' => ['admin', 'StrongPassword!'],
            'teacher missing symbol' => ['teacher', 'StrongPassword123'],
            'student below minimum length' => ['student', 'Short1!aA'],
        ];
    }
}
