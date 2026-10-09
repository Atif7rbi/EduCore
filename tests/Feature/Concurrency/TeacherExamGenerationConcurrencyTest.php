<?php

namespace Tests\Feature\Concurrency;

use App\Application\Curriculum\RetireCurriculumVersion;
use App\Application\Exceptions\TeacherAuthoringConflict;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesH5ExamConcurrencyFixtures;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\Support\PostgresProcessBarrier;
use Tests\TestCase;

class TeacherExamGenerationConcurrencyTest extends TestCase
{
    use CreatesH5ExamConcurrencyFixtures;
    use CreatesOwnedCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    public function test_template_publish_then_generation_serializes_to_complete_generation(): void
    {
        $fixture = $this->h5ExamFixture();
        $templateVersionId = $fixture['template_versions'][0];

        [$publish, $generation] = $this->race(
            'exam_template_versions',
            $templateVersionId,
            $this->templatePayload($fixture, 'teacher_template_publish', $templateVersionId),
            $this->generationPayload($fixture, $templateVersionId, 'publish-then-generate'),
        );

        $this->assertWorkerSucceeded($publish, 'teacher_template_publish');
        $this->assertWorkerSucceeded($generation, 'teacher_build_exam_generation');
        $this->assertGenerationPersisted(
            $fixture,
            $generation['result']['generation_id'],
            $templateVersionId,
            'publish-then-generate',
        );
    }

    public function test_generation_rejects_after_source_template_version_is_retired_five_times(): void
    {
        for ($run = 1; $run <= 5; $run++) {
            $fixture = $this->h5ExamFixture(templateVersionCount: 2);
            [$sourceVersionId, $currentVersionId] = $fixture['template_versions'];
            $this->h5PublishTemplateVersion($fixture, $sourceVersionId);
            DB::table('exam_template_versions')->where('id', $currentVersionId)->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);
            DB::table('exam_templates')->where('id', $fixture['template_id'])->update([
                'published_version_id' => $currentVersionId,
                'updated_at' => now(),
            ]);

            $seed = 'retired-source-'.$run;
            [$retire, $generation] = $this->race(
                'exam_templates',
                $fixture['template_id'],
                $this->templatePayload($fixture, 'teacher_template_retire', $sourceVersionId),
                $this->generationPayload($fixture, $sourceVersionId, $seed),
            );

            $this->assertWorkerSucceeded($retire, 'teacher_template_retire');
            $this->assertWorkerDomainFailure(
                $generation,
                'teacher_build_exam_generation',
                TeacherAuthoringConflict::class,
            );
            $this->assertNoGenerationPersisted($seed);
            $this->assertSame('retired', DB::table('exam_template_versions')
                ->where('id', $sourceVersionId)->value('status'));
            $this->assertSame($currentVersionId, DB::table('exam_templates')
                ->where('id', $fixture['template_id'])->value('published_version_id'));
        }
    }

    public function test_generation_rejects_after_curriculum_version_retirement(): void
    {
        $fixture = $this->h5ExamFixture();
        $templateVersionId = $fixture['template_versions'][0];
        $this->h5PublishTemplateVersion($fixture, $templateVersionId);
        DB::table('curriculum_versions')->where('id', $fixture['curriculum_version_id'])->update([
            'status' => 'published',
            'updated_at' => now(),
        ]);
        $seed = 'retired-curriculum';
        $barrier = null;
        DB::beginTransaction();

        try {
            DB::table('curriculum_versions')
                ->where('id', $fixture['curriculum_version_id'])
                ->lockForUpdate()
                ->firstOrFail();
            app(RetireCurriculumVersion::class)->execute($fixture['curriculum_version_id']);
            $barrier = PostgresProcessBarrier::start(
                $this->generationPayload($fixture, $templateVersionId, $seed),
                'phase_h5_concurrency_worker.php',
            );
            $ready = $barrier->awaitReady();
            $barrier->release();
            $barrier->awaitBlockedByCurrentConnection($ready['pid']);
            DB::commit();
            $result = $barrier->finish();

            $this->assertWorkerDomainFailure(
                $result,
                'teacher_build_exam_generation',
                TeacherAuthoringConflict::class,
            );
            $this->assertNoGenerationPersisted($seed);
            $this->assertSame('retired', DB::table('curriculum_versions')
                ->where('id', $fixture['curriculum_version_id'])->value('status'));
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $barrier?->cleanup();
        }
    }

    public function test_generation_cannot_bypass_teacher_disable_five_times(): void
    {
        for ($run = 1; $run <= 5; $run++) {
            $fixture = $this->h5ExamFixture();
            $templateVersionId = $fixture['template_versions'][0];
            $this->h5PublishTemplateVersion($fixture, $templateVersionId);
            $barrier = null;
            DB::beginTransaction();

            try {
                DB::table('users')->where('id', $fixture['actor_user_id'])->lockForUpdate()->firstOrFail();
                DB::table('users')->where('id', $fixture['actor_user_id'])->update([
                    'status' => 'disabled',
                    'updated_at' => now(),
                ]);
                $seed = 'disabled-teacher-'.$run;
                $barrier = PostgresProcessBarrier::start(
                    $this->generationPayload($fixture, $templateVersionId, $seed),
                    'phase_h5_concurrency_worker.php',
                );
                $ready = $barrier->awaitReady();
                $barrier->release();
                $barrier->awaitBlockedByCurrentConnection($ready['pid']);
                DB::commit();
                $result = $barrier->finish();

                $this->assertWorkerDomainFailure(
                    $result,
                    'teacher_build_exam_generation',
                    ModelNotFoundException::class,
                );
                $this->assertNoGenerationPersisted($seed);
            } finally {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                $barrier?->cleanup();
            }
        }
    }

    public function test_shared_source_generation_and_attempt_complete_on_distinct_committed_artifacts(): void
    {
        $fixture = $this->h5ExamFixture(withLearner: true);
        $templateVersionId = $fixture['template_versions'][0];
        $this->h5PublishTemplateVersion($fixture, $templateVersionId);
        $existingGenerationId = $this->h5BuildGeneration($fixture, $templateVersionId, 'existing-generation');
        DB::table('curriculum_versions')->where('id', $fixture['curriculum_version_id'])->update([
            'status' => 'published',
            'updated_at' => now(),
        ]);

        [$generation, $attempt] = $this->race(
            'exam_template_versions',
            $templateVersionId,
            $this->generationPayload($fixture, $templateVersionId, 'concurrent-generation'),
            [
                'action' => 'build_exam_attempt',
                'authenticated_user_id' => $fixture['learner_user_id'],
                'learner_profile_id' => $fixture['learner_profile_id'],
                'generation_id' => $existingGenerationId,
            ],
        );

        $this->assertWorkerSucceeded($generation, 'teacher_build_exam_generation');
        $this->assertWorkerSucceeded($attempt, 'build_exam_attempt');
        $this->assertGenerationPersisted(
            $fixture,
            $generation['result']['generation_id'],
            $templateVersionId,
            'concurrent-generation',
        );
        $this->assertAttemptSnapshot(
            $fixture,
            $attempt['result']['attempt_id'],
            $existingGenerationId,
        );
    }

    public function test_exam_attempt_cannot_consume_uncommitted_generation_and_consumes_after_commit(): void
    {
        $fixture = $this->h5ExamFixture(withLearner: true);
        $templateVersionId = $fixture['template_versions'][0];
        $this->h5PublishTemplateVersion($fixture, $templateVersionId);
        DB::table('curriculum_versions')->where('id', $fixture['curriculum_version_id'])->update([
            'status' => 'published',
            'updated_at' => now(),
        ]);

        $pendingGenerationFile = $this->signalPath('educore-h5-r3-generation-');
        $commitFile = $this->signalPath('educore-h5-r3-commit-');
        $generationWorker = null;
        $incompleteAttemptWorker = null;
        $committedAttemptWorker = null;
        $seed = 'pending-visibility-boundary';

        try {
            $generationWorker = PostgresProcessBarrier::start(
                $this->generationPayload(
                    $fixture,
                    $templateVersionId,
                    $seed,
                    'teacher_build_exam_generation_pending_commit',
                ) + [
                    'pending_generation_file' => $pendingGenerationFile,
                    'commit_generation_file' => $commitFile,
                ],
                'phase_h5_concurrency_worker.php',
            );
            $generationWorker->awaitReady();
            $generationWorker->release();
            $generationId = $this->awaitPendingGenerationId($pendingGenerationFile);

            $this->assertSame(0, DB::table('exam_generations')->where('id', $generationId)->count());
            $this->assertSame(0, DB::table('exam_generation_items')->where('exam_generation_id', $generationId)->count());

            $incompleteAttemptWorker = PostgresProcessBarrier::start(
                $this->attemptPayload($fixture, $generationId),
                'phase_h5_concurrency_worker.php',
            );
            $incompleteAttemptWorker->awaitReady();
            $incompleteAttemptWorker->release();
            $incompleteAttempt = $incompleteAttemptWorker->finish();
            $this->assertWorkerDomainFailure(
                $incompleteAttempt,
                'build_exam_attempt',
                ModelNotFoundException::class,
            );

            file_put_contents($commitFile, "COMMIT\n", LOCK_EX);
            $generation = $generationWorker->finish();
            $this->assertWorkerSucceeded(
                $generation,
                'teacher_build_exam_generation_pending_commit',
            );
            $this->assertSame($generationId, $generation['result']['generation_id']);
            $this->assertGenerationPersisted($fixture, $generationId, $templateVersionId, $seed);

            $committedAttemptWorker = PostgresProcessBarrier::start(
                $this->attemptPayload($fixture, $generationId),
                'phase_h5_concurrency_worker.php',
            );
            $committedAttemptWorker->awaitReady();
            $committedAttemptWorker->release();
            $committedAttempt = $committedAttemptWorker->finish();
            $this->assertWorkerSucceeded($committedAttempt, 'build_exam_attempt');
            $this->assertAttemptSnapshot(
                $fixture,
                $committedAttempt['result']['attempt_id'],
                $generationId,
            );
        } finally {
            if ($generationWorker !== null && ! is_file($commitFile)) {
                file_put_contents($commitFile, "COMMIT\n", LOCK_EX);
            }
            $generationWorker?->cleanup();
            $incompleteAttemptWorker?->cleanup();
            $committedAttemptWorker?->cleanup();
            @unlink($pendingGenerationFile);
            @unlink($commitFile);
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function race(string $table, string $id, array $firstPayload, array $secondPayload): array
    {
        $first = null;
        $second = null;
        DB::beginTransaction();

        try {
            DB::table($table)->where('id', $id)->lockForUpdate()->firstOrFail();
            $first = PostgresProcessBarrier::start($firstPayload, 'phase_h5_concurrency_worker.php');
            $second = PostgresProcessBarrier::start($secondPayload, 'phase_h5_concurrency_worker.php');
            $firstReady = $first->awaitReady();
            $secondReady = $second->awaitReady();
            $first->release();
            $first->awaitBlockedByCurrentConnection($firstReady['pid']);
            $second->release();
            $second->awaitBlockedByProcess($firstReady['pid'], $secondReady['pid']);
            DB::commit();

            return [$first->finish(), $second->finish()];
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $first?->cleanup();
            $second?->cleanup();
        }
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function templatePayload(array $fixture, string $action, string $templateVersionId): array
    {
        return [
            'action' => $action,
            'actor_user_id' => $fixture['actor_user_id'],
            'assignment_id' => $fixture['assignment_id'],
            'curriculum_id' => $fixture['curriculum_id'],
            'curriculum_version_id' => $fixture['curriculum_version_id'],
            'template_id' => $fixture['template_id'],
            'template_version_id' => $templateVersionId,
        ];
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function generationPayload(
        array $fixture,
        string $templateVersionId,
        string $seed,
        string $action = 'teacher_build_exam_generation',
    ): array {
        return $this->templatePayload($fixture, $action, $templateVersionId) + [
            'generator_version' => 'h5-r3',
            'seed' => $seed,
            'revision_ids' => [$fixture['revision_id']],
        ];
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function attemptPayload(array $fixture, string $generationId): array
    {
        return [
            'action' => 'build_exam_attempt',
            'authenticated_user_id' => $fixture['learner_user_id'],
            'learner_profile_id' => $fixture['learner_profile_id'],
            'generation_id' => $generationId,
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function assertGenerationPersisted(
        array $fixture,
        string $generationId,
        string $templateVersionId,
        string $seed,
    ): void {
        $generation = DB::table('exam_generations')->where('id', $generationId)->first();
        $this->assertNotNull($generation);
        $this->assertSame($templateVersionId, $generation->exam_template_version_id);
        $this->assertSame($fixture['curriculum_version_id'], $generation->curriculum_version_id);
        $this->assertSame($seed, $generation->seed);
        $this->assertNotNull($generation->generated_at);

        $items = DB::table('exam_generation_items')
            ->where('exam_generation_id', $generationId)
            ->orderBy('selection_position')
            ->get();
        $this->assertCount(1, $items);
        $this->assertSame(0, $items[0]->selection_position);
        $this->assertSame($fixture['revision_id'], $items[0]->assessment_item_revision_id);
        $this->assertSame($fixture['item_id'], $items[0]->assessment_item_id);
        $this->assertSame($fixture['curriculum_version_id'], $items[0]->curriculum_version_id);
        $this->assertSame(1, count(array_unique(array_map(
            static fn (object $item): string => $item->assessment_item_revision_id,
            $items->all(),
        ))));

        $revision = DB::table('assessment_item_revisions')
            ->where('id', $fixture['revision_id'])
            ->first();
        $this->assertNotNull($revision);
        $this->assertSame($fixture['item_id'], $revision->assessment_item_id);
        $this->assertSame($fixture['curriculum_version_id'], $revision->curriculum_version_id);
        $this->assertNotNull($revision->released_at);
    }

    private function assertNoGenerationPersisted(string $seed): void
    {
        $this->assertSame(0, DB::table('exam_generations')->where('seed', $seed)->count());
        $this->assertSame(0, DB::table('exam_generation_items as item')
            ->join('exam_generations as generation', 'generation.id', '=', 'item.exam_generation_id')
            ->where('generation.seed', $seed)
            ->count());
    }

    /** @param array<string, mixed> $fixture */
    private function assertAttemptSnapshot(array $fixture, string $attemptId, string $generationId): void
    {
        $attempt = DB::table('attempts')->where('id', $attemptId)->first();
        $this->assertNotNull($attempt);
        $this->assertSame($generationId, $attempt->exam_generation_id);
        $this->assertSame($fixture['curriculum_version_id'], $attempt->curriculum_version_id);

        $items = DB::table('attempt_items')
            ->where('attempt_id', $attemptId)
            ->orderBy('presentation_position')
            ->get();
        $this->assertCount(1, $items);
        $this->assertSame(0, $items[0]->presentation_position);
        $this->assertSame($fixture['revision_id'], $items[0]->assessment_item_revision_id);
        $this->assertSame($fixture['item_id'], $items[0]->assessment_item_id);
        $this->assertSame($fixture['curriculum_version_id'], $items[0]->curriculum_version_id);
        $this->assertSame($generationId, $items[0]->exam_generation_id);
        $this->assertNotNull($items[0]->exam_generation_item_id);
        $this->assertSame(1, count(array_unique(array_map(
            static fn (object $item): string => $item->assessment_item_revision_id,
            $items->all(),
        ))));
        $this->assertSame(1, DB::table('exam_generation_items')
            ->where('id', $items[0]->exam_generation_item_id)
            ->where('exam_generation_id', $generationId)
            ->where('assessment_item_revision_id', $fixture['revision_id'])
            ->count());
    }

    private function signalPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertNotFalse($path);
        unlink($path);

        return $path;
    }

    private function awaitPendingGenerationId(string $path): string
    {
        $deadline = microtime(true) + 8.0;

        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                $this->fail('Timed out waiting for pending ExamGeneration identity.');
            }
            usleep(10_000);
        }

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('generation_id', $payload);
        $this->assertIsString($payload['generation_id']);

        return $payload['generation_id'];
    }

    /** @param array<string, mixed> $result */
    private function assertWorkerSucceeded(array $result, string $action): void
    {
        foreach (['ok', 'operation', 'failure_type', 'result', 'exception_class', 'message', 'sqlstate', '_exit_code'] as $field) {
            $this->assertArrayHasKey($field, $result);
        }
        $this->assertTrue($result['ok'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame($action, $result['operation']);
        $this->assertNull($result['failure_type']);
        $this->assertSame(0, $result['_exit_code']);
    }

    /** @param array<string, mixed> $result */
    private function assertWorkerDomainFailure(array $result, string $action, string $exception): void
    {
        foreach (['ok', 'operation', 'failure_type', 'result', 'exception_class', 'message', 'sqlstate', '_exit_code'] as $field) {
            $this->assertArrayHasKey($field, $result);
        }
        $this->assertFalse($result['ok']);
        $this->assertSame($action, $result['operation']);
        $this->assertSame('domain', $result['failure_type']);
        $this->assertNull($result['result']);
        $this->assertSame($exception, $result['exception_class']);
        $this->assertNull($result['sqlstate']);
        $this->assertSame(0, $result['_exit_code']);
    }
}
