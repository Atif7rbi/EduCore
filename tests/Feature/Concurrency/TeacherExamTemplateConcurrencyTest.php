<?php

namespace Tests\Feature\Concurrency;

use App\Application\Exceptions\TeacherAuthoringConflict;
use App\Application\TeacherAuthoring\ManageTeacherPracticeExamAuthoring;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesH5ExamConcurrencyFixtures;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\Support\PostgresProcessBarrier;
use Tests\TestCase;

class TeacherExamTemplateConcurrencyTest extends TestCase
{
    use CreatesH5ExamConcurrencyFixtures;
    use CreatesOwnedCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    public function test_template_version_update_then_publish_serializes_five_times(): void
    {
        for ($run = 1; $run <= 5; $run++) {
            $fixture = $this->h5ExamFixture();
            $templateVersionId = $fixture['template_versions'][0];
            $label = 'updated-'.$run;
            $rules = ['question_count' => $run, 'marker' => $label];

            [$update, $publish] = $this->race(
                'exam_template_versions',
                $templateVersionId,
                $this->templatePayload($fixture, 'teacher_template_update', $templateVersionId) + [
                    'label' => $label,
                    'rules_payload' => $rules,
                    'rules_schema_version' => 2,
                ],
                $this->templatePayload($fixture, 'teacher_template_publish', $templateVersionId),
            );

            $this->assertWorkerSucceeded($update, 'teacher_template_update');
            $this->assertWorkerSucceeded($publish, 'teacher_template_publish');
            $version = DB::table('exam_template_versions')->where('id', $templateVersionId)->first();
            $template = DB::table('exam_templates')->where('id', $fixture['template_id'])->first();
            $this->assertNotNull($version);
            $this->assertNotNull($template);
            $this->assertSame('published', $version->status);
            $this->assertSame($label, $version->label);
            $this->assertEquals($rules, json_decode($version->rules_payload, true, 512, JSON_THROW_ON_ERROR));
            $this->assertSame(2, $version->rules_schema_version);
            $this->assertSame($templateVersionId, $template->published_version_id);
        }
    }

    public function test_publish_new_version_then_retire_old_noncurrent_version_serializes(): void
    {
        $fixture = $this->h5ExamFixture(templateVersionCount: 2);
        [$oldVersionId, $newVersionId] = $fixture['template_versions'];
        $this->h5PublishTemplateVersion($fixture, $oldVersionId);

        [$publish, $retire] = $this->race(
            'exam_templates',
            $fixture['template_id'],
            $this->templatePayload($fixture, 'teacher_template_publish', $newVersionId),
            $this->templatePayload($fixture, 'teacher_template_retire', $oldVersionId),
        );

        $this->assertWorkerSucceeded($publish, 'teacher_template_publish');
        $this->assertWorkerSucceeded($retire, 'teacher_template_retire');
        $template = DB::table('exam_templates')->where('id', $fixture['template_id'])->first();
        $old = DB::table('exam_template_versions')->where('id', $oldVersionId)->first();
        $new = DB::table('exam_template_versions')->where('id', $newVersionId)->first();
        $this->assertSame($newVersionId, $template->published_version_id);
        $this->assertSame('retired', $old->status);
        $this->assertSame('published', $new->status);

        try {
            app(ManageTeacherPracticeExamAuthoring::class)->retireTemplateVersion(
                $fixture['actor_user_id'], $fixture['assignment_id'], $fixture['curriculum_id'],
                $fixture['curriculum_version_id'], $fixture['template_id'], $newVersionId,
            );
            $this->fail('Expected current published version retirement rejection.');
        } catch (TeacherAuthoringConflict $exception) {
            $this->assertSame('exam_template_version_is_current', $exception->errorCode);
        }

        $this->assertSame($newVersionId, DB::table('exam_templates')
            ->where('id', $fixture['template_id'])->value('published_version_id'));
        $this->assertSame('published', DB::table('exam_template_versions')
            ->where('id', $newVersionId)->value('status'));
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
}
