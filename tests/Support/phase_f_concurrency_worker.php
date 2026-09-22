<?php

declare(strict_types=1);

use App\Application\Attempt\BuildExamAttempt;
use App\Application\Attempt\BuildPracticeAttempt;
use App\Application\Attempt\FinalizeAttempt;
use App\Application\Attempt\SaveAttemptResponse;
use App\Application\Authorization\LockActiveLearnerCurriculumGrant;
use App\Application\Enrollment\DeactivateStudentEnrollment;
use App\Application\Learning\RecordLessonProgress;
use App\Application\Learning\UnpublishLesson;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? null) === null) {
    fwrite(
        STDERR,
        "Missing concurrency worker payload.\n"
    );

    exit(2);
}

try {
    $decoded = base64_decode(
        $argv[1],
        true,
    );

    if ($decoded === false) {
        throw new RuntimeException(
            'Invalid concurrency worker payload encoding.'
        );
    }

    $payload = json_decode(
        $decoded,
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    if (! is_array($payload)) {
        throw new RuntimeException(
            'Invalid concurrency worker payload.'
        );
    }

    foreach ([
        'action',
        'ready_file',
        'go_file',
        'result_file',
    ] as $required) {
        if (
            ! isset($payload[$required])
            || ! is_string(
                $payload[$required]
            )
            || $payload[$required] === ''
        ) {
            throw new RuntimeException(
                "Missing worker field: {$required}"
            );
        }
    }

    $identity = DB::selectOne(
        <<<'SQL'
SELECT
    current_database() AS database_name,
    current_user AS database_user,
    pg_backend_pid() AS backend_pid
SQL
    );

    if (
        $identity === null
        || $identity->database_name
            !== 'sewaellf_educore_test'
        || $identity->database_user
            !== 'sewaellf_educore_Admin'
    ) {
        throw new RuntimeException(
            'Worker refused non-dedicated '
            .'PostgreSQL identity.'
        );
    }

    $writeJson = static function (
        string $path,
        array $value,
    ): void {
        $temporary =
            $path.'.tmp.'.getmypid();

        $bytes = file_put_contents(
            $temporary,
            json_encode(
                $value,
                JSON_THROW_ON_ERROR,
            ),
            LOCK_EX,
        );

        if ($bytes === false) {
            throw new RuntimeException(
                "Unable to write worker signal: {$path}"
            );
        }

        if (! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(
                "Unable to publish worker signal: {$path}"
            );
        }
    };

    $writeJson(
        $payload['ready_file'],
        [
            'pid' => (int) $identity->backend_pid,
            'database' => (string)
                $identity->database_name,
            'user' => (string)
                $identity->database_user,
        ],
    );

    $deadline = microtime(true) + 8.0;

    while (
        ! is_file($payload['go_file'])
    ) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException(
                'Timed out waiting for GO barrier.'
            );
        }

        usleep(10_000);
    }

    try {
        $data = match (
            $payload['action']
        ) {
            'lock_user' => DB::transaction(
                static function () use (
                    $payload
                ): array {
                    $user =
                        User::query()
                            ->whereKey(
                                $payload[
                                    'user_id'
                                ]
                            )
                            ->lockForUpdate()
                            ->firstOrFail();

                    return [
                        'id' => $user->id,
                    ];
                }
            ),

            'build_practice_attempt' => (static function () use (
                $payload
            ): array {
                $attempt = app(
                    BuildPracticeAttempt::class
                )->execute(
                    $payload[
                        'authenticated_user_id'
                    ],
                    $payload[
                        'learner_profile_id'
                    ],
                    $payload[
                        'practice_activity_id'
                    ],
                );

                return [
                    'id' => $attempt->id,
                    'status' => $attempt->status,
                ];
            })(),

            'build_exam_attempt' => (static function () use (
                $payload
            ): array {
                $attempt = app(
                    BuildExamAttempt::class
                )->execute(
                    $payload[
                        'authenticated_user_id'
                    ],
                    $payload[
                        'learner_profile_id'
                    ],
                    $payload[
                        'exam_generation_id'
                    ],
                );

                return [
                    'id' => $attempt->id,
                    'status' => $attempt->status,
                ];
            })(),

            'deactivate_student_enrollment' => (static function () use (
                $payload
            ): array {
                $enrollment = app(
                    DeactivateStudentEnrollment::class
                )->execute(
                    actorUserId: $payload[
                            'actor_user_id'
                        ],
                    enrollmentId: $payload[
                            'enrollment_id'
                        ],
                    operationId: $payload[
                            'operation_id'
                        ],
                    reason: $payload['reason'],
                );

                return [
                    'id' => $enrollment->id,
                    'status' => $enrollment->status,
                ];
            })(),

            'lock_active_learner_curriculum_grant' => (static function () use (
                $payload
            ): array {
                $enrollment =
                    DB::transaction(
                        static function () use (
                            $payload
                        ) {
                            return app(
                                LockActiveLearnerCurriculumGrant::class
                            )->execute(
                                $payload[
                                    'authenticated_user_id'
                                ],
                                $payload[
                                    'learner_profile_id'
                                ],
                                $payload[
                                    'curriculum_version_id'
                                ],
                            );
                        }
                    );

                return [
                    'id' => $enrollment->id,
                    'status' => $enrollment->status,
                ];
            })(),

            'deactivate_teacher_subject_assignment' => (static function () use (
                $payload
            ): array {
                $assignment = app(
                    DeactivateTeacherSubjectAssignment::class
                )->execute(
                    actorUserId: $payload[
                            'actor_user_id'
                        ],
                    assignmentId: $payload[
                            'assignment_id'
                        ],
                    operationId: $payload[
                            'operation_id'
                        ],
                    reason: $payload['reason'],
                );

                return [
                    'id' => $assignment->id,
                    'status' => $assignment->status,
                ];
            })(),

            'save_attempt_response' => (static function () use (
                $payload
            ): array {
                $response = app(
                    SaveAttemptResponse::class
                )->execute(
                    $payload[
                        'authenticated_user_id'
                    ],
                    $payload[
                        'learner_profile_id'
                    ],
                    $payload[
                        'attempt_item_id'
                    ],
                    $payload[
                        'response_payload'
                    ],
                    (int)
                    $payload[
                        'time_spent_ms'
                    ],
                );

                return [
                    'id' => $response->id,
                    'attempt_item_id' => $response
                        ->attempt_item_id,
                ];
            })(),

            'finalize_attempt' => (static function () use (
                $payload
            ): array {
                $attempt = app(
                    FinalizeAttempt::class
                )->execute(
                    $payload[
                        'authenticated_user_id'
                    ],
                    $payload[
                        'learner_profile_id'
                    ],
                    $payload[
                        'attempt_id'
                    ],
                    $payload[
                        'final_status'
                    ]
                        ?? 'submitted',
                );

                return [
                    'id' => $attempt->id,
                    'status' => $attempt->status,
                ];
            })(),

            'record_lesson_progress' => (static function () use (
                $payload
            ): array {
                $progress = app(
                    RecordLessonProgress::class
                )->execute(
                    $payload[
                        'authenticated_user_id'
                    ],
                    $payload[
                        'learner_profile_id'
                    ],
                    $payload[
                        'lesson_id'
                    ],
                    (bool) (
                        $payload['complete']
                            ?? false
                    ),
                );

                return [
                    'id' => $progress->id,
                    'status' => $progress->status,
                ];
            })(),

            'unpublish_lesson' => (static function () use (
                $payload
            ): array {
                $lesson = app(
                    UnpublishLesson::class
                )->execute(
                    $payload['lesson_id']
                );

                return [
                    'id' => $lesson->id,
                    'status' => $lesson->status,
                ];
            })(),

            default => throw new RuntimeException(
                'Unknown concurrency worker action: '
                .$payload['action']
            ),
        };

        $writeJson(
            $payload['result_file'],
            [
                'result' => 'success',
                'action' => $payload['action'],
                'data' => $data,
            ],
        );
    } catch (Throwable $exception) {
        $writeJson(
            $payload['result_file'],
            [
                'result' => 'exception',
                'action' => $payload['action'],
                'class' => $exception::class,
                'message' => $exception->getMessage(),
            ],
        );
    }
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        $exception::class
        .': '
        .$exception->getMessage()
        .PHP_EOL,
    );

    exit(1);
}
