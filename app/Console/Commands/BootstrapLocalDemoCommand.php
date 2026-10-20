<?php

namespace App\Console\Commands;

use App\Application\LocalDemo\BootstrapLocalDemo;
use App\Application\LocalDemo\LocalDemoEnvironmentGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BootstrapLocalDemoCommand extends Command
{
    private const CREDENTIAL_FILE = 'credentials.json';

    private const PENDING_CREDENTIAL_FILE = 'credentials.pending.json';

    private const TEST_CREDENTIAL_DIRECTORY = 'EDUCORE_LOCAL_DEMO_TEST_CREDENTIAL_DIRECTORY';

    private const TEST_FAULT = 'EDUCORE_LOCAL_DEMO_TEST_FAULT';

    protected $signature = 'educore:bootstrap-local-demo
                            {--recover : Finalize a committed local demo credential handoff without provisioning}';

    protected $description = 'Create or recover the guarded local-only EduCore demonstration dataset';

    private bool $unresolvedArtifact = false;

    public function handle(
        LocalDemoEnvironmentGuard $environment,
        BootstrapLocalDemo $bootstrap,
    ): int {
        try {
            $environment->assertSafe();
            $bootstrap->assertOutermostTransaction();
        } catch (Throwable) {
            $this->components->error('Local demo bootstrap refused: the environment safety contract failed.');

            return self::FAILURE;
        }

        if ((bool) $this->option('recover')) {
            return $this->recoverCredentialHandoff($bootstrap);
        }

        $adminPassword = $this->promptPassword('Admin');
        $teacherPassword = $this->promptPassword('Teacher');
        $studentPassword = $this->promptPassword('Student');

        if ($adminPassword === null || $teacherPassword === null || $studentPassword === null) {
            $this->components->error('Local demo bootstrap refused: invalid password confirmation.');

            return self::FAILURE;
        }

        $operationIds = [
            'assignment' => (string) Str::uuid(),
            'enrollment_request' => (string) Str::uuid(),
            'enrollment_acceptance' => (string) Str::uuid(),
        ];
        $artifact = null;
        $databaseCommitted = false;

        try {
            $bootstrap->assertValidCredentials($adminPassword, $teacherPassword, $studentPassword);
            $bootstrap->assertValidOperationIds(...array_values($operationIds));
            $artifact = $this->stageCredentialArtifact(
                $this->credentialPayload(
                    $adminPassword,
                    $teacherPassword,
                    $studentPassword,
                    $operationIds,
                ),
            );

            $bootstrap->execute(
                $adminPassword,
                $teacherPassword,
                $studentPassword,
                $operationIds['assignment'],
                $operationIds['enrollment_request'],
                $operationIds['enrollment_acceptance'],
            );
            $databaseCommitted = true;

            if ($this->testFault('after_commit')) {
                throw new RuntimeException('Synthetic interruption after commit.');
            }

            $this->finalizeCredentialArtifact($artifact, $bootstrap);
        } catch (Throwable $exception) {
            if ($databaseCommitted) {
                $this->components->error(
                    'Local demo data was committed, but credential handoff is incomplete and recoverable. Run this command with --recover; do not run bootstrap again.',
                );

                return self::FAILURE;
            }

            if ((int) $exception->getCode() === BootstrapLocalDemo::INDETERMINATE_COMMIT_CODE) {
                $this->components->error(
                    'Local demo database commit outcome is indeterminate; the pending credential handoff was preserved. Run this command with --recover; do not run bootstrap again.',
                );

                return self::FAILURE;
            }

            $cleanupSucceeded = ! $this->unresolvedArtifact
                && ($artifact === null || $this->cleanupUncommittedArtifact($artifact));

            $this->components->error(
                $cleanupSucceeded
                    ? 'Local demo bootstrap failed safely; no demo data or credential artifact was committed.'
                    : 'Local demo bootstrap failed and an unresolved pending credential artifact requires operator attention.',
            );

            return self::FAILURE;
        }

        $this->components->info('Local demo data created successfully. Credentials are in the private local handoff file.');

        return self::SUCCESS;
    }

    private function recoverCredentialHandoff(BootstrapLocalDemo $bootstrap): int
    {
        try {
            $directory = $this->credentialDirectory();
            $this->assertPrivateDirectory($directory);
            $pendingPath = $directory.DIRECTORY_SEPARATOR.self::PENDING_CREDENTIAL_FILE;
            $finalPath = $directory.DIRECTORY_SEPARATOR.self::CREDENTIAL_FILE;
            $pendingExists = @lstat($pendingPath) !== false;
            $finalExists = @lstat($finalPath) !== false;

            if (! $pendingExists && ! $finalExists) {
                throw new RuntimeException('No recoverable local demo credential handoff exists.');
            }

            if ($finalExists && ! $pendingExists) {
                $payload = $this->readCredentialArtifact($finalPath, $bootstrap);
                $this->assertCommittedBootstrap($payload);
                $this->components->info('Local demo credential handoff is already complete.');

                return self::SUCCESS;
            }

            $payload = $this->readCredentialArtifact($pendingPath, $bootstrap);
            $this->assertCommittedBootstrap($payload);
            $artifact = [
                'directory' => $directory,
                'pending_path' => $pendingPath,
                'final_path' => $finalPath,
                'created_directory' => false,
                'provenance_sha256' => $payload['provenance_sha256'],
            ];

            if ($finalExists) {
                $this->assertLinkedArtifacts($pendingPath, $finalPath);
                $this->removePendingArtifact($artifact);
            } else {
                $this->finalizeCredentialArtifact($artifact, $bootstrap);
            }

            $this->components->info('Local demo credential handoff recovered successfully.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->components->error(
                'Local demo credential recovery refused: pending or committed state is missing, ambiguous, or inconsistent.',
            );

            return self::FAILURE;
        }
    }

    private function promptPassword(string $account): ?string
    {
        $password = $this->secret("{$account} demo account password");
        $confirmation = $this->secret("Confirm {$account} demo account password");

        if (! is_string($password) || ! hash_equals($password, (string) $confirmation)) {
            return null;
        }

        return $password;
    }

    /**
     * @param  array{assignment: string, enrollment_request: string, enrollment_acceptance: string}  $operationIds
     * @return array<string, mixed>
     */
    private function credentialPayload(
        string $adminPassword,
        string $teacherPassword,
        string $studentPassword,
        array $operationIds,
    ): array {
        $payload = [
            'version' => 1,
            'bootstrap_run_id' => (string) Str::uuid(),
            'created_at_utc' => gmdate('c'),
            'operations' => $operationIds,
            'accounts' => [
                'admin' => ['email' => BootstrapLocalDemo::ADMIN_EMAIL, 'password' => $adminPassword],
                'teacher' => ['email' => BootstrapLocalDemo::TEACHER_EMAIL, 'password' => $teacherPassword],
                'student' => ['email' => BootstrapLocalDemo::STUDENT_EMAIL, 'password' => $studentPassword],
            ],
        ];
        $payload['provenance_sha256'] = $this->payloadDigest($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{directory: string, pending_path: string, final_path: string, created_directory: bool, provenance_sha256: string}
     */
    private function stageCredentialArtifact(array $payload): array
    {
        $directory = $this->credentialDirectory();
        $createdDirectory = $this->prepareCredentialDirectory($directory);
        $pendingPath = $directory.DIRECTORY_SEPARATOR.self::PENDING_CREDENTIAL_FILE;
        $finalPath = $directory.DIRECTORY_SEPARATOR.self::CREDENTIAL_FILE;

        if (@lstat($pendingPath) !== false || @lstat($finalPath) !== false) {
            if ($createdDirectory && ! $this->removeCreatedDirectory($directory)) {
                $this->unresolvedArtifact = true;
            }

            throw new RuntimeException('A local demo credential handoff already exists.');
        }

        $handle = @fopen($pendingPath, 'x+b');

        if ($handle === false) {
            if ($createdDirectory && ! $this->removeCreatedDirectory($directory)) {
                $this->unresolvedArtifact = true;
            }

            throw new RuntimeException('Unable to stage the local demo credential handoff.');
        }

        try {
            if (! @chmod($pendingPath, 0600)) {
                throw new RuntimeException('Unable to protect the local demo credential handoff.');
            }

            $encoded = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL;
            $this->writeAll($handle, $encoded);

            $flushed = fflush($handle);
            $synced = $flushed && fsync($handle);
            $closed = fclose($handle);
            $handle = null;

            if (! $flushed || ! $synced || ! $closed) {
                throw new RuntimeException('Unable to persist the local demo credential handoff.');
            }

            $handle = null;
            $this->assertPrivateRegularFile($pendingPath);
            $this->syncDirectory($directory);
            $readBack = $this->readRawPayload($pendingPath);
            $readBackDigest = $readBack['provenance_sha256'] ?? null;
            unset($readBack['provenance_sha256']);

            if (
                ! is_string($readBackDigest)
                || ! hash_equals($payload['provenance_sha256'], $readBackDigest)
                || ! hash_equals($payload['provenance_sha256'], $this->payloadDigest($readBack))
            ) {
                throw new RuntimeException('Credential handoff provenance verification failed.');
            }
        } catch (Throwable) {
            if (is_resource($handle) && ! fclose($handle)) {
                $this->unresolvedArtifact = true;
            }

            $artifact = [
                'directory' => $directory,
                'pending_path' => $pendingPath,
                'final_path' => $finalPath,
                'created_directory' => $createdDirectory,
                'provenance_sha256' => (string) $payload['provenance_sha256'],
            ];

            if (! $this->cleanupUncommittedArtifact($artifact)) {
                $this->unresolvedArtifact = true;
            }

            throw new RuntimeException('Unable to stage the local demo credential handoff.');
        }

        return [
            'directory' => $directory,
            'pending_path' => $pendingPath,
            'final_path' => $finalPath,
            'created_directory' => $createdDirectory,
            'provenance_sha256' => (string) $payload['provenance_sha256'],
        ];
    }

    /** @param array{directory: string, pending_path: string, final_path: string, created_directory: bool, provenance_sha256: string} $artifact */
    private function finalizeCredentialArtifact(array $artifact, BootstrapLocalDemo $bootstrap): void
    {
        $this->assertPrivateDirectory($artifact['directory']);
        $payload = $this->readCredentialArtifact($artifact['pending_path'], $bootstrap);

        if (! hash_equals($artifact['provenance_sha256'], $payload['provenance_sha256'])) {
            throw new RuntimeException('Credential handoff provenance changed.');
        }

        $this->assertCommittedBootstrap($payload);

        if ($this->testFault('finalization')) {
            throw new RuntimeException('Synthetic post-commit finalization failure.');
        }

        if (@lstat($artifact['final_path']) !== false) {
            throw new RuntimeException('The local demo credential handoff already exists.');
        }

        if (! @link($artifact['pending_path'], $artifact['final_path'])) {
            throw new RuntimeException('Unable to finalize the local demo credential handoff.');
        }

        $this->syncDirectory($artifact['directory']);
        $this->assertLinkedArtifacts($artifact['pending_path'], $artifact['final_path']);
        $this->removePendingArtifact($artifact);
        $finalPayload = $this->readCredentialArtifact($artifact['final_path'], $bootstrap);

        if (! hash_equals($artifact['provenance_sha256'], $finalPayload['provenance_sha256'])) {
            throw new RuntimeException('Final credential handoff provenance changed.');
        }
    }

    /** @param array{directory: string, pending_path: string, final_path: string, created_directory: bool, provenance_sha256: string} $artifact */
    private function cleanupUncommittedArtifact(array $artifact): bool
    {
        if ($this->testFault('cleanup')) {
            return false;
        }

        if (@lstat($artifact['final_path']) !== false) {
            return false;
        }

        if (@lstat($artifact['pending_path']) !== false) {
            if (! @unlink($artifact['pending_path'])) {
                return false;
            }

            try {
                $this->syncDirectory($artifact['directory']);
            } catch (Throwable) {
                return false;
            }
        }

        if (@lstat($artifact['pending_path']) !== false) {
            return false;
        }

        return ! $artifact['created_directory'] || $this->removeCreatedDirectory($artifact['directory']);
    }

    /** @param array{directory: string, pending_path: string, final_path: string, created_directory: bool, provenance_sha256: string} $artifact */
    private function removePendingArtifact(array $artifact): void
    {
        if (! @unlink($artifact['pending_path'])) {
            throw new RuntimeException('Unable to remove the finalized pending credential handoff.');
        }

        $this->syncDirectory($artifact['directory']);

        if (@lstat($artifact['pending_path']) !== false) {
            throw new RuntimeException('The pending credential handoff remains after finalization.');
        }
    }

    private function removeCreatedDirectory(string $directory): bool
    {
        if (@lstat($directory) === false) {
            return true;
        }

        if (! @rmdir($directory)) {
            return false;
        }

        try {
            $this->syncDirectory(dirname($directory));
        } catch (Throwable) {
            return false;
        }

        return @lstat($directory) === false;
    }

    /** @return array<string, mixed> */
    private function readCredentialArtifact(string $path, BootstrapLocalDemo $bootstrap): array
    {
        $this->assertPrivateRegularFile($path);
        $payload = $this->readRawPayload($path);

        if (
            array_keys($payload) !== ['version', 'bootstrap_run_id', 'created_at_utc', 'operations', 'accounts', 'provenance_sha256']
            || $payload['version'] !== 1
            || ! is_string($payload['bootstrap_run_id'])
            || ! Str::isUuid($payload['bootstrap_run_id'])
            || ! is_string($payload['created_at_utc'])
            || ! is_array($payload['operations'])
            || array_keys($payload['operations']) !== ['assignment', 'enrollment_request', 'enrollment_acceptance']
            || ! is_array($payload['accounts'])
            || array_keys($payload['accounts']) !== ['admin', 'teacher', 'student']
            || ! is_string($payload['provenance_sha256'])
        ) {
            throw new RuntimeException('Credential handoff structure is invalid.');
        }

        foreach (['admin', 'teacher', 'student'] as $account) {
            if (
                ! is_array($payload['accounts'][$account])
                || array_keys($payload['accounts'][$account]) !== ['email', 'password']
                || ! is_string($payload['accounts'][$account]['email'])
                || ! is_string($payload['accounts'][$account]['password'])
            ) {
                throw new RuntimeException('Credential handoff account structure is invalid.');
            }
        }

        $expectedEmails = [
            'admin' => BootstrapLocalDemo::ADMIN_EMAIL,
            'teacher' => BootstrapLocalDemo::TEACHER_EMAIL,
            'student' => BootstrapLocalDemo::STUDENT_EMAIL,
        ];

        foreach ($expectedEmails as $account => $email) {
            if (! hash_equals($email, $payload['accounts'][$account]['email'])) {
                throw new RuntimeException('Credential handoff identity is invalid.');
            }
        }

        $bootstrap->assertValidCredentials(
            $payload['accounts']['admin']['password'],
            $payload['accounts']['teacher']['password'],
            $payload['accounts']['student']['password'],
        );
        $bootstrap->assertValidOperationIds(
            $payload['operations']['assignment'],
            $payload['operations']['enrollment_request'],
            $payload['operations']['enrollment_acceptance'],
        );

        $providedDigest = $payload['provenance_sha256'];
        unset($payload['provenance_sha256']);
        $computedDigest = $this->payloadDigest($payload);
        $payload['provenance_sha256'] = $providedDigest;

        if (! hash_equals($computedDigest, $providedDigest)) {
            throw new RuntimeException('Credential handoff provenance is invalid.');
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function readRawPayload(string $path): array
    {
        $contents = @file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to read the local demo credential handoff.');
        }

        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new RuntimeException('The local demo credential handoff is invalid.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function payloadDigest(array $payload): string
    {
        unset($payload['provenance_sha256']);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload */
    private function assertCommittedBootstrap(array $payload): void
    {
        $users = DB::table('users')->whereIn('email', BootstrapLocalDemo::EMAILS)->get()->keyBy('email');
        $this->requireState($users->count() === 3 && DB::table('users')->where('role', 'admin')->count() === 1);

        foreach (['admin', 'teacher', 'student'] as $role) {
            $account = $payload['accounts'][$role];
            $user = $users->get($account['email']);
            $this->requireState(
                $user !== null
                && $user->role === $role
                && $user->status === 'active'
                && Hash::check($account['password'], $user->password),
            );
        }

        $admin = $users->get(BootstrapLocalDemo::ADMIN_EMAIL);
        $teacher = $users->get(BootstrapLocalDemo::TEACHER_EMAIL);
        $student = $users->get(BootstrapLocalDemo::STUDENT_EMAIL);
        $provisioning = DB::table('teacher_account_provisionings')->where('teacher_user_id', $teacher->id)->first();
        $learner = DB::table('learner_profiles')->where('user_id', $student->id)->first();
        $this->requireState($provisioning !== null && $provisioning->setup_completed_at !== null && $learner !== null);

        $assignmentTransition = DB::table('teacher_subject_assignment_transitions')
            ->where('operation_id', $payload['operations']['assignment'])->first();
        $this->requireState(
            $assignmentTransition !== null
            && $assignmentTransition->actor_user_id === $admin->id
            && $assignmentTransition->from_status === null
            && $assignmentTransition->to_status === 'active'
            && $assignmentTransition->reason === 'Local demonstration mathematics assignment',
        );
        $assignment = DB::table('teacher_subject_assignments')->where('id', $assignmentTransition->assignment_id)->first();
        $subject = $assignment === null ? null : DB::table('subjects')->where('id', $assignment->subject_id)->first();
        $this->requireState(
            $assignment !== null
            && $assignment->teacher_id === $teacher->id
            && $assignment->status === 'active'
            && $subject !== null
            && $subject->code === 'mathematics'
            && $subject->status === 'active',
        );

        $requestTransition = DB::table('student_enrollment_transitions')
            ->where('operation_id', $payload['operations']['enrollment_request'])->first();
        $acceptTransition = DB::table('student_enrollment_transitions')
            ->where('operation_id', $payload['operations']['enrollment_acceptance'])->first();
        $this->requireState(
            $requestTransition !== null
            && $acceptTransition !== null
            && $requestTransition->enrollment_id === $acceptTransition->enrollment_id
            && $requestTransition->actor_user_id === $student->id
            && $requestTransition->from_status === null
            && $requestTransition->to_status === 'pending'
            && $requestTransition->outcome === 'requested'
            && $requestTransition->reason === 'Local demonstration enrollment request'
            && $acceptTransition->actor_user_id === $teacher->id
            && $acceptTransition->from_status === 'pending'
            && $acceptTransition->to_status === 'active'
            && $acceptTransition->outcome === 'accepted'
            && $acceptTransition->reason === 'Local demonstration enrollment acceptance',
        );
        $enrollment = DB::table('student_enrollments')->where('id', $requestTransition->enrollment_id)->first();
        $this->requireState(
            $enrollment !== null
            && $enrollment->learner_profile_id === $learner->id
            && $enrollment->teacher_subject_assignment_id === $assignment->id
            && $enrollment->status === 'active',
        );

        $curriculum = DB::table('curricula')
            ->where('teacher_subject_assignment_id', $assignment->id)
            ->where('name', 'Local Mathematics Demonstration')->first();
        $this->requireState($curriculum !== null);
        $versions = DB::table('curriculum_versions')->where('curriculum_id', $curriculum->id)->get()->keyBy('version_number');
        $this->requireState(
            $versions->count() === 2
            && $versions->get(1)?->status === 'published'
            && $versions->get(2)?->status === 'draft',
        );
        $publishedVersion = $versions->get(1);
        $skill = DB::table('skills')->where('name', 'Local arithmetic fluency')->first();
        $this->requireState($skill !== null);
        $this->requireState(DB::table('skill_version_placements')
            ->where('curriculum_version_id', $publishedVersion->id)->where('skill_id', $skill->id)->count() === 1);
        $lesson = DB::table('lessons')->where('curriculum_version_id', $publishedVersion->id)->first();
        $assessment = DB::table('assessment_items')->where('curriculum_version_id', $publishedVersion->id)->first();
        $practice = DB::table('practice_activities')->where('curriculum_version_id', $publishedVersion->id)->first();
        $template = DB::table('exam_templates')->where('curriculum_version_id', $publishedVersion->id)->first();
        $this->requireState(
            $lesson !== null && $lesson->status === 'published'
            && $assessment !== null && $assessment->status === 'published'
            && $practice !== null && $practice->status === 'active'
            && $template !== null && $template->status === 'active',
        );
        $this->requireState(DB::table('lesson_revisions')->where('lesson_id', $lesson->id)->whereNotNull('released_at')->count() === 1);
        $this->requireState(DB::table('assessment_item_revisions')->where('assessment_item_id', $assessment->id)->whereNotNull('released_at')->count() === 1);
        $this->requireState(DB::table('practice_activity_items')->where('practice_activity_id', $practice->id)->count() === 1);
        $templateVersion = DB::table('exam_template_versions')->where('exam_template_id', $template->id)->first();
        $this->requireState($templateVersion !== null && $templateVersion->status === 'published');
        $generation = DB::table('exam_generations')->where('exam_template_version_id', $templateVersion->id)->first();
        $this->requireState($generation !== null && DB::table('exam_generation_items')->where('exam_generation_id', $generation->id)->count() === 1);
    }

    private function requireState(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('Committed local demo state does not match credential provenance.');
        }
    }

    private function assertLinkedArtifacts(string $pendingPath, string $finalPath): void
    {
        $this->assertPrivateRegularFile($pendingPath);
        $this->assertPrivateRegularFile($finalPath);
        $pending = @lstat($pendingPath);
        $final = @lstat($finalPath);
        $pendingHash = @hash_file('sha256', $pendingPath);
        $finalHash = @hash_file('sha256', $finalPath);

        if (
            $pending === false
            || $final === false
            || $pending['dev'] !== $final['dev']
            || $pending['ino'] !== $final['ino']
            || ! is_string($pendingHash)
            || ! is_string($finalHash)
            || ! hash_equals($pendingHash, $finalHash)
        ) {
            throw new RuntimeException('Credential handoff finalization is inconsistent.');
        }
    }

    private function credentialDirectory(): string
    {
        if (app()->runningUnitTests()) {
            $testDirectory = getenv(self::TEST_CREDENTIAL_DIRECTORY);

            if (! is_string($testDirectory) || ! str_starts_with($testDirectory, '/')) {
                throw new RuntimeException('The test credential directory is not safe.');
            }

            return $testDirectory;
        }

        if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
            throw new RuntimeException('The local account identity cannot be resolved safely.');
        }

        $account = posix_getpwuid(posix_geteuid());
        $home = is_array($account) ? ($account['dir'] ?? null) : null;

        if (! is_string($home) || ! str_starts_with($home, '/')) {
            throw new RuntimeException('The local account home cannot be resolved safely.');
        }

        return rtrim($home, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.educore-local-demo';
    }

    private function prepareCredentialDirectory(string $directory): bool
    {
        if (str_contains($directory, DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The local demo credential directory is not safe.');
        }

        $parent = dirname($directory);
        $this->assertNoSymlinkTraversal($parent);
        $parentMetadata = @lstat($parent);

        if ($parentMetadata === false || ($parentMetadata['mode'] & 0170000) !== 0040000) {
            throw new RuntimeException('The local demo credential parent directory is not safe.');
        }

        $created = false;

        if (@lstat($directory) === false) {
            if (! @mkdir($directory, 0700, false)) {
                throw new RuntimeException('Unable to create the local demo credential directory.');
            }

            $created = true;
        }

        try {
            if ($created) {
                $this->syncDirectory($parent);
            }

            $this->assertPrivateDirectory($directory);
            $resolvedDirectory = realpath($directory);
            $resolvedBase = realpath(base_path());
            $resolvedPublic = realpath(public_path());

            if (
                $resolvedDirectory === false
                || $this->pathIsWithin($resolvedDirectory, $resolvedBase)
                || $this->pathIsWithin($resolvedDirectory, $resolvedPublic)
            ) {
                throw new RuntimeException('The local demo credential directory is not isolated.');
            }
        } catch (Throwable) {
            if ($created && ! $this->removeCreatedDirectory($directory)) {
                $this->unresolvedArtifact = true;
            }

            throw new RuntimeException('The local demo credential directory is not safe.');
        }

        return $created;
    }

    private function assertNoSymlinkTraversal(string $path): void
    {
        $resolved = DIRECTORY_SEPARATOR;

        foreach (array_filter(explode(DIRECTORY_SEPARATOR, $path)) as $segment) {
            $resolved = rtrim($resolved, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$segment;
            $metadata = @lstat($resolved);

            if ($metadata === false || ($metadata['mode'] & 0170000) !== 0040000) {
                throw new RuntimeException('The local demo credential path contains an unsafe component.');
            }
        }
    }

    private function pathIsWithin(string $path, string|false $root): bool
    {
        if ($root === false) {
            return false;
        }

        return $path === $root
            || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function assertPrivateDirectory(string $path): void
    {
        $metadata = @lstat($path);

        if (
            $metadata === false
            || ($metadata['mode'] & 0170000) !== 0040000
            || ($metadata['mode'] & 0777) !== 0700
            || $metadata['uid'] !== posix_geteuid()
        ) {
            throw new RuntimeException('The local demo credential directory is not private.');
        }
    }

    private function assertPrivateRegularFile(string $path): void
    {
        $metadata = @lstat($path);

        if (
            $metadata === false
            || ($metadata['mode'] & 0170000) !== 0100000
            || ($metadata['mode'] & 0777) !== 0600
            || $metadata['uid'] !== posix_geteuid()
        ) {
            throw new RuntimeException('The local demo credential handoff is not private.');
        }
    }

    private function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'r');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the credential directory for synchronization.');
        }

        $synced = @fsync($handle);
        $closed = @fclose($handle);

        if (! $synced || ! $closed) {
            throw new RuntimeException('Unable to synchronize the credential directory.');
        }
    }

    private function testFault(string $fault): bool
    {
        return app()->runningUnitTests() && getenv(self::TEST_FAULT) === $fault;
    }

    /** @param resource $handle */
    private function writeAll($handle, string $payload): void
    {
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            $written = fwrite($handle, substr($payload, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write the local demo credential handoff.');
            }

            $offset += $written;
        }
    }
}
