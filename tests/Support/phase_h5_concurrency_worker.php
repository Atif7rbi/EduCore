<?php

declare(strict_types=1);

use App\Application\Assessment\PublishAssessmentItem;
use App\Application\Assessment\RetireAssessmentItem;
use App\Application\Attempt\BuildExamAttempt;
use App\Application\Attempt\BuildPracticeAttempt;
use App\Application\Exam\BuildExamGeneration;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Exceptions\TeacherAuthoringConflict;
use App\Application\Learning\PublishLesson;
use App\Application\Learning\ReleaseLessonRevision;
use App\Application\Learning\UnpublishLesson;
use App\Application\Practice\AddPracticeActivityItem;
use App\Application\Practice\RemovePracticeActivityItem;
use App\Application\Support\TransactionManager;
use App\Application\TeacherAuthoring\ManageTeacherLessonAssessmentAuthoring;
use App\Application\TeacherAuthoring\ManageTeacherPracticeExamAuthoring;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode(base64_decode($argv[1] ?? '', true) ?: '', true, 512, JSON_THROW_ON_ERROR);
foreach (['action', 'ready_file', 'go_file', 'result_file'] as $field) {
    if (! isset($payload[$field]) || ! is_string($payload[$field]) || $payload[$field] === '') {
        throw new RuntimeException("Missing H5 worker field: {$field}");
    }
}
$identity = DB::selectOne('SELECT current_database() AS database_name, current_user AS database_user, pg_backend_pid() AS backend_pid');
if ($identity === null || $identity->database_name !== 'sewaellf_educore_test' || $identity->database_user !== 'sewaellf_educore_Admin') {
    throw new RuntimeException('H5 worker refused non-dedicated PostgreSQL identity.');
}
$write = static function (string $path, array $value): void {
    $tmp = $path.'.tmp.'.getmypid();
    file_put_contents($tmp, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX);
    rename($tmp, $path);
};
$write($payload['ready_file'], ['pid' => (int) $identity->backend_pid, 'database' => $identity->database_name, 'user' => $identity->database_user]);
$deadline = microtime(true) + 8.0;
while (! is_file($payload['go_file'])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for H5 GO barrier.');
    }
    usleep(10_000);
}
$waitForFile = static function (string $path, string $label): void {
    $deadline = microtime(true) + 8.0;

    while (! is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for '.$label.'.');
        }
        usleep(10_000);
    }
};

$pauseAfterAuthority = static function (callable $lock) use ($identity, $payload, $write): string {
    $authorizedVersionId = $lock();
    $lockedFile = $payload['authority_locked_file'] ?? null;
    $continueFile = $payload['authority_continue_file'] ?? null;

    if ($lockedFile === null && $continueFile === null) {
        return $authorizedVersionId;
    }

    if (! is_string($lockedFile) || ! is_string($continueFile) || $lockedFile === '' || $continueFile === '') {
        throw new RuntimeException('Invalid H5 authority-lock synchronization payload.');
    }

    $write($lockedFile, ['pid' => (int) $identity->backend_pid]);
    $deadline = microtime(true) + 8.0;
    while (! is_file($continueFile)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for H5 authority-lock continuation.');
        }
        usleep(10_000);
    }

    return $authorizedVersionId;
};

try {
    $data = match ($payload['action']) {
        'lesson_publish' => (static function () use ($payload, $pauseAfterAuthority): array {
            $a = app(ManageTeacherLessonAssessmentAuthoring::class);
            $r = app(PublishLesson::class)->execute($payload['lesson_id'], $payload['revision_id'], fn (): string => $pauseAfterAuthority(fn (): string => $a->lockLessonLifecycle($payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'], $payload['curriculum_version_id'], $payload['lesson_id'], $payload['revision_id'])));

            return ['status' => $r->status];
        })(),
        'lesson_unpublish' => (static function () use ($payload, $pauseAfterAuthority): array {
            $a = app(ManageTeacherLessonAssessmentAuthoring::class);
            $r = app(UnpublishLesson::class)->execute($payload['lesson_id'], fn (): string => $pauseAfterAuthority(fn (): string => $a->lockLessonLifecycle($payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'], $payload['curriculum_version_id'], $payload['lesson_id'])));

            return ['status' => $r->status];
        })(),
        'lesson_release_revision' => (static function () use ($payload, $pauseAfterAuthority): array {
            $a = app(ManageTeacherLessonAssessmentAuthoring::class);
            $r = app(ReleaseLessonRevision::class)->execute($payload['revision_id'], fn (): string => $pauseAfterAuthority(fn (): string => $a->lockLessonLifecycle($payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'], $payload['curriculum_version_id'], $payload['lesson_id'], $payload['revision_id'])));

            return ['id' => $r->id];
        })(),
        'assessment_publish' => (static function () use ($payload, $pauseAfterAuthority): array {
            $a = app(ManageTeacherLessonAssessmentAuthoring::class);
            $r = app(PublishAssessmentItem::class)->execute($payload['item_id'], $payload['revision_id'], fn (): string => $pauseAfterAuthority(fn (): string => $a->lockAssessmentLifecycle($payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'], $payload['curriculum_version_id'], $payload['item_id'], $payload['revision_id'])));

            return ['status' => $r->status];
        })(),
        'assessment_retire' => (static function () use ($payload, $pauseAfterAuthority): array {
            $a = app(ManageTeacherLessonAssessmentAuthoring::class);
            $r = app(RetireAssessmentItem::class)->execute($payload['item_id'], fn (): string => $pauseAfterAuthority(fn (): string => $a->lockAssessmentLifecycle($payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'], $payload['curriculum_version_id'], $payload['item_id'])));

            return ['status' => $r->status];
        })(),
        'assessment_create_teacher_revision' => (static function () use ($payload): array {
            $r = app(ManageTeacherLessonAssessmentAuthoring::class)->createAssessmentRevision($payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'], $payload['curriculum_version_id'], $payload['item_id'], $payload['candidate_revision_number'], null, 'easy', ['q' => 'h5'], 1, ['a' => 'h5'], 1);

            return [
                'revision_id' => $r->id,
                'revision_number' => $r->revision_number,
            ];
        })(),
        'h5_test_unexpected_exception' => throw new RuntimeException('H5 worker protocol unexpected-exception probe'),
        'h5_test_sqlstate_violation' => DB::select('SELECT 1 / 0'),
        'h5_test_translated_integrity_violation' => (static function (): array {
            (new TransactionManager(new PostgresExceptionTranslator))->run(
                static function (): void {
                    DB::unprepared(
                        'DO $$ BEGIN RAISE EXCEPTION \'H5 translated integrity probe\' USING ERRCODE = \'23505\'; END $$;'
                    );
                },
            );

            return [];
        })(),
        'practice_add_item' => (static function () use ($payload): array {
            $membership = app(AddPracticeActivityItem::class)->execute(
                $payload['practice_id'],
                $payload['revision_id'],
                $payload['item_id'],
                $payload['display_order'],
            );

            return [
                'membership_id' => $membership->id,
                'assessment_item_revision_id' => $membership->assessment_item_revision_id,
            ];
        })(),
        'practice_remove_item' => (static function () use ($payload): array {
            app(RemovePracticeActivityItem::class)->execute(
                $payload['practice_id'],
                $payload['membership_id'],
            );

            return ['membership_id' => $payload['membership_id']];
        })(),
        'teacher_template_update' => (static function () use ($payload): array {
            $version = app(ManageTeacherPracticeExamAuthoring::class)->updateTemplateVersion(
                $payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'],
                $payload['curriculum_version_id'], $payload['template_id'], $payload['template_version_id'],
                $payload['label'], $payload['rules_payload'], $payload['rules_schema_version'],
            );

            return ['id' => $version->id, 'label' => $version->label, 'status' => $version->status];
        })(),
        'teacher_template_publish' => (static function () use ($payload): array {
            $version = app(ManageTeacherPracticeExamAuthoring::class)->publishTemplateVersion(
                $payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'],
                $payload['curriculum_version_id'], $payload['template_id'], $payload['template_version_id'],
            );

            return ['id' => $version->id, 'status' => $version->status];
        })(),
        'teacher_template_retire' => (static function () use ($payload): array {
            $version = app(ManageTeacherPracticeExamAuthoring::class)->retireTemplateVersion(
                $payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'],
                $payload['curriculum_version_id'], $payload['template_id'], $payload['template_version_id'],
            );

            return ['id' => $version->id, 'status' => $version->status];
        })(),
        'teacher_build_exam_generation' => (static function () use ($payload): array {
            $authoring = app(ManageTeacherPracticeExamAuthoring::class);
            $items = $authoring->generationItems(
                $payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'],
                $payload['curriculum_version_id'], $payload['template_id'], $payload['template_version_id'],
                $payload['revision_ids'],
            );
            $generation = app(BuildExamGeneration::class)->execute(
                $payload['template_version_id'], $payload['generator_version'], $payload['seed'], $items,
                fn (): string => $authoring->lockGenerationAuthority(
                    $payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'],
                    $payload['curriculum_version_id'], $payload['template_id'], $payload['template_version_id'],
                    $payload['revision_ids'],
                ),
            );

            return ['generation_id' => $generation->id];
        })(),
        'teacher_build_exam_generation_pending_commit' => (static function () use ($payload, $waitForFile, $write): array {
            foreach (['pending_generation_file', 'commit_generation_file'] as $field) {
                if (! isset($payload[$field]) || ! is_string($payload[$field]) || $payload[$field] === '') {
                    throw new RuntimeException("Missing H5 pending-generation field: {$field}");
                }
            }

            DB::beginTransaction();

            try {
                $authoring = app(ManageTeacherPracticeExamAuthoring::class);
                $items = $authoring->generationItems(
                    $payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'],
                    $payload['curriculum_version_id'], $payload['template_id'], $payload['template_version_id'],
                    $payload['revision_ids'],
                );
                $generation = app(BuildExamGeneration::class)->execute(
                    $payload['template_version_id'], $payload['generator_version'], $payload['seed'], $items,
                    fn (): string => $authoring->lockGenerationAuthority(
                        $payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'],
                        $payload['curriculum_version_id'], $payload['template_id'], $payload['template_version_id'],
                        $payload['revision_ids'],
                    ),
                );
                $write($payload['pending_generation_file'], ['generation_id' => $generation->id]);
                $waitForFile($payload['commit_generation_file'], 'H5 generation commit signal');
                DB::commit();

                return ['generation_id' => $generation->id];
            } catch (Throwable $exception) {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                throw $exception;
            }
        })(),
        'build_exam_attempt' => (static function () use ($payload): array {
            $attempt = app(BuildExamAttempt::class)->execute(
                $payload['authenticated_user_id'], $payload['learner_profile_id'], $payload['generation_id'],
            );

            return ['attempt_id' => $attempt->id];
        })(),
        'teacher_practice_add_items' => (static function () use ($payload): array {
            $memberships = app(ManageTeacherPracticeExamAuthoring::class)->addPracticeItems(
                $payload['actor_user_id'],
                $payload['assignment_id'],
                $payload['curriculum_id'],
                $payload['curriculum_version_id'],
                $payload['practice_id'],
                $payload['revision_ids'],
                $payload['display_order'],
            );

            return ['count' => count($memberships)];
        })(),
        'build_practice_attempt' => (static function () use ($payload): array {
            $attempt = app(BuildPracticeAttempt::class)->execute(
                $payload['authenticated_user_id'],
                $payload['learner_profile_id'],
                $payload['practice_id'],
            );

            return ['attempt_id' => $attempt->id];
        })(),
        'add_practice_items' => (static function () use ($payload): array {
            $items = app(ManageTeacherPracticeExamAuthoring::class)->addPracticeItems(
                $payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'], $payload['curriculum_version_id'], $payload['practice_id'], $payload['revision_ids'], 0,
            );

            return ['count' => count($items)];
        })(),
        'create_practice' => (static function () use ($payload): array {
            $practice = app(ManageTeacherPracticeExamAuthoring::class)->createPractice(
                $payload['actor_user_id'], $payload['assignment_id'], $payload['curriculum_id'], $payload['curriculum_version_id'], null, $payload['name'], null,
            );

            return ['id' => $practice->id, 'status' => $practice->status];
        })(),
        default => throw new RuntimeException('Unknown H5 concurrency action: '.$payload['action']),
    };
    $envelope = ['ok' => true, 'operation' => $payload['action'], 'failure_type' => null, 'result' => $data, 'exception_class' => null, 'message' => null, 'sqlstate' => null];
    $write($payload['result_file'], $envelope);
    fwrite(STDOUT, json_encode($envelope, JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    $sqlstate = isset($exception->errorInfo[0]) ? $exception->errorInfo[0] : ($exception->getPrevious()?->getCode() ?: null);
    $sqlstate = $exception instanceof IntegrityConstraintViolation
        ? $exception->sqlState
        : $sqlstate;
    $domain = $exception instanceof TeacherAuthoringConflict
        || $exception instanceof ModelNotFoundException;
    $write($payload['result_file'], ['ok' => false, 'operation' => $payload['action'], 'failure_type' => $domain ? 'domain' : 'unexpected', 'result' => null, 'exception_class' => $exception::class, 'message' => $exception->getMessage(), 'sqlstate' => is_string($sqlstate) && preg_match('/^[0-9A-Z]{5}$/', $sqlstate) ? $sqlstate : null]);
    if (! $domain) {
        exit(1);
    }
}
