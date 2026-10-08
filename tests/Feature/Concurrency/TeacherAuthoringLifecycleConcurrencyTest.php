<?php

namespace Tests\Feature\Concurrency;

use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\Exceptions\TeacherAuthoringConflict;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Models\AssessmentItem;
use App\Models\AssessmentItemRevision;
use App\Models\AssessmentItemRevisionSkill;
use App\Models\CurriculumVersion;
use App\Models\Lesson;
use App\Models\LessonRevision;
use App\Models\Skill;
use App\Models\SkillVersionPlacement;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\Support\PostgresProcessBarrier;
use Tests\TestCase;

class TeacherAuthoringLifecycleConcurrencyTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures, ResetsDedicatedTestDatabase;

    public function test_lesson_publish_and_unpublish_serialize(): void
    {
        $this->assertMaskedWorkerFailureIsRejected();

        $f = $this->fixture();
        $x = $this->lesson($f, true, true);
        [$unpublishResult, $publishResult] = $this->race(
            $f,
            ['lesson_unpublish', 'lesson_publish'],
            $x,
            forceFirstWorker: true,
        );
        $this->assertWorkerSucceeded($unpublishResult, 'lesson_unpublish');
        $this->assertWorkerSucceeded($publishResult, 'lesson_publish');
        $row = DB::table('lessons')->where('id', $x['id'])->first();
        $this->assertContains($row->status, ['published', 'unpublished']);
        if ($row->status === 'published') {
            $this->assertSame($x['revision'], $row->published_revision_id);
        }
    }

    public function test_lesson_publish_and_revision_lifecycle_serialize(): void
    {
        $f = $this->fixture();
        $x = $this->lesson($f, false);
        [$releaseResult, $publishResult] = $this->race(
            $f,
            ['lesson_release_revision', 'lesson_publish'],
            $x,
            forceFirstWorker: true,
        );
        $lesson = DB::table('lessons')->where('id', $x['id'])->first();
        $revision = DB::table('lesson_revisions')->where('id', $x['revision'])->first();

        $this->assertWorkerSucceeded($publishResult, 'lesson_publish');
        $this->assertWorkerSucceeded($releaseResult, 'lesson_release_revision');
        $this->assertNotNull($revision->released_at);
        if ($lesson->status === 'published') {
            $this->assertSame($x['revision'], $lesson->published_revision_id);
            $this->assertNotNull($revision->released_at);
        }
    }

    public function test_assessment_publish_and_retire_serialize(): void
    {
        $f = $this->fixture();
        $x = $this->assessment($f);
        [$publishResult, $retireResult] = $this->race(
            $f,
            ['assessment_publish', 'assessment_retire'],
            $x,
            forceFirstWorker: true,
        );
        $this->assertWorkerSucceeded($publishResult, 'assessment_publish');
        $this->assertWorkerSucceeded($retireResult, 'assessment_retire');
        $row = DB::table('assessment_items')->where('id', $x['id'])->first();
        $this->assertContains($row->status, ['published', 'retired']);
        $this->assertSame($x['revision'], $row->published_revision_id);
    }

    public function test_assessment_publish_and_teacher_revision_creation_serialize(): void
    {
        $f = $this->fixture();
        $x = $this->assessment($f);
        [$publishResult, $teacherResult] = $this->race(
            $f,
            ['assessment_publish', 'assessment_create_teacher_revision'],
            $x,
        );
        $item = DB::table('assessment_items')->where('id', $x['id'])->first();
        $candidate = DB::table('assessment_item_revisions')
            ->where('assessment_item_id', $x['id'])
            ->where('revision_number', $x['candidate_revision_number'])
            ->first();
        $candidateExists = $candidate !== null;
        $count = DB::table('assessment_item_revisions')
            ->where('assessment_item_id', $x['id'])
            ->count();

        $this->assertTrue($publishResult['ok'] ?? false);
        $this->assertArrayHasKey('failure_type', $publishResult);
        $this->assertNull($publishResult['failure_type']);
        $this->assertSame('assessment_publish', $publishResult['operation'] ?? null);
        $this->assertSame(0, $publishResult['_exit_code'] ?? null);
        $this->assertSame('published', $item->status);
        $this->assertSame($x['revision'], $item->published_revision_id);

        $this->assertSame(
            'assessment_create_teacher_revision',
            $teacherResult['operation'] ?? null,
        );

        if ($teacherResult['ok'] ?? false) {
            $this->assertArrayHasKey('failure_type', $teacherResult);
            $this->assertNull($teacherResult['failure_type']);
            $this->assertSame(0, $teacherResult['_exit_code'] ?? null);
            $this->assertTrue($candidateExists);
            $this->assertSame($candidate->id, $teacherResult['result']['revision_id'] ?? null);
            $this->assertSame($x['candidate_revision_number'], $teacherResult['result']['revision_number'] ?? null);
            $this->assertSame(2, $count);
        } else {
            $this->assertSame('domain', $teacherResult['failure_type'] ?? null);
            $this->assertSame(0, $teacherResult['_exit_code'] ?? null);
            $this->assertSame(
                TeacherAuthoringConflict::class,
                $teacherResult['exception_class'] ?? null,
            );
            $this->assertSame('New revisions may only be authored for draft assessment items.', $teacherResult['message'] ?? null);
            $this->assertFalse($candidateExists);
            $this->assertSame(1, $count);
        }
    }

    private function race(array $f, array $actions, array $x, bool $forceFirstWorker = false): array
    {
        $authorityLockedFile = null;
        $authorityContinueFile = null;
        $payload = fn ($action) => array_merge($f, $x, ['action' => $action]);
        $firstPayload = $payload($actions[0]);
        if ($forceFirstWorker) {
            $authorityLockedFile = $this->signalPath('h5-authority-locked-');
            $authorityContinueFile = $this->signalPath('h5-authority-continue-');
            $firstPayload['authority_locked_file'] = $authorityLockedFile;
            $firstPayload['authority_continue_file'] = $authorityContinueFile;
        }

        $a = PostgresProcessBarrier::start($firstPayload, 'phase_h5_concurrency_worker.php');
        $b = PostgresProcessBarrier::start($payload($actions[1]), 'phase_h5_concurrency_worker.php');
        try {
            $aReady = $a->awaitReady();
            $bReady = $b->awaitReady();
            $a->release();

            if ($forceFirstWorker) {
                $this->awaitSignal($authorityLockedFile);
                $b->release();
                $b->awaitBlockedByProcess($aReady['pid'], $bReady['pid']);
                file_put_contents($authorityContinueFile, "GO\n", LOCK_EX);
            } else {
                $b->release();
            }

            $ra = $a->finish();
            $rb = $b->finish();
            $this->assertWorkerDidNotFailUnexpectedly($ra, $actions[0]);
            $this->assertWorkerDidNotFailUnexpectedly($rb, $actions[1]);

            return [$ra, $rb];
        } finally {
            $a->cleanup();
            $b->cleanup();
            foreach ([$authorityLockedFile, $authorityContinueFile] as $path) {
                if (is_string($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function signalPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new RuntimeException('Unable to allocate H5 authority-lock signal path.');
        }
        @unlink($path);

        return $path;
    }

    private function awaitSignal(?string $path): void
    {
        if ($path === null) {
            throw new RuntimeException('Missing H5 authority-lock signal path.');
        }

        $deadline = microtime(true) + 8.0;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for H5 authority lock.');
            }
            usleep(10_000);
        }
    }

    /** @param array<string, mixed> $result */
    private function assertWorkerDidNotFailUnexpectedly(array $result, string $operation): void
    {
        foreach ([
            'ok',
            'operation',
            'failure_type',
            'result',
            'exception_class',
            'message',
            'sqlstate',
            '_exit_code',
        ] as $field) {
            $this->assertArrayHasKey($field, $result);
        }

        $this->assertIsBool($result['ok']);
        $this->assertSame($operation, $result['operation']);
        $this->assertSame(0, $result['_exit_code'], json_encode($result, JSON_THROW_ON_ERROR));

        if ($result['ok']) {
            $this->assertNull($result['failure_type']);

            return;
        }

        $this->assertFalse($result['ok']);
        $this->assertSame('domain', $result['failure_type']);
    }

    /** @param array<string, mixed> $result */
    private function assertWorkerSucceeded(array $result, string $operation): void
    {
        $this->assertWorkerDidNotFailUnexpectedly($result, $operation);
        $this->assertTrue($result['ok']);
        $this->assertNull($result['failure_type']);
        $this->assertSame(0, $result['_exit_code']);
    }

    private function assertMaskedWorkerFailureIsRejected(): void
    {
        foreach ([
            [
                'ok' => false,
                'operation' => 'synthetic',
                'failure_type' => 'unexpected',
                'result' => null,
                'exception_class' => RuntimeException::class,
                'message' => 'unexpected',
                'sqlstate' => null,
                '_exit_code' => 0,
            ],
            [
                'ok' => true,
                'operation' => 'synthetic',
                'failure_type' => null,
                'result' => [],
                'exception_class' => null,
                'message' => null,
                'sqlstate' => null,
                '_exit_code' => 1,
            ],
        ] as $result) {
            $rejected = false;

            try {
                $this->assertWorkerDidNotFailUnexpectedly($result, 'synthetic');
            } catch (AssertionFailedError) {
                $rejected = true;
            }

            $this->assertTrue($rejected);
        }
    }

    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $as = app(AssignTeacherSubject::class)->execute($admin->id, $teacher->id, $this->canonicalSubjectId('mathematics'), (string) Str::uuid(), 'h5');
        $c = app(CreateOwnedCurriculum::class)->execute($teacher->id, $as->id, 'h5', null);
        $v = CurriculumVersion::query()->create(['curriculum_id' => $c->id, 'version_number' => 1, 'label' => 'h5', 'status' => 'draft']);

        return ['actor_user_id' => $teacher->id, 'assignment_id' => $as->id, 'curriculum_id' => $c->id, 'curriculum_version_id' => $v->id];
    }

    private function lesson(array $f, bool $released, bool $published = false): array
    {
        $topic = Topic::query()->create(['curriculum_version_id' => $f['curriculum_version_id'], 'name' => 'h5 '.Str::random(6), 'display_order' => 0]);
        $l = Lesson::query()->create(['curriculum_version_id' => $f['curriculum_version_id'], 'title' => 'h5', 'description' => null, 'status' => 'draft', 'display_order' => 0, 'published_revision_id' => null]);
        $r = LessonRevision::query()->create(['lesson_id' => $l->id, 'curriculum_version_id' => $f['curriculum_version_id'], 'revision_number' => 1, 'primary_topic_id' => $topic->id, 'content_payload' => ['x' => 1], 'content_schema_version' => 1, 'released_at' => null]);
        if ($released) {
            DB::table('lesson_revisions')->where('id', $r->id)->update(['released_at' => now()]);
        }
        if ($published) {
            DB::table('lessons')->where('id', $l->id)->update([
                'status' => 'published',
                'published_revision_id' => $r->id,
            ]);
        }

        return ['id' => $l->id, 'lesson_id' => $l->id, 'revision' => $r->id, 'revision_id' => $r->id];
    }

    private function assessment(array $f): array
    {
        $i = AssessmentItem::query()->create(['curriculum_version_id' => $f['curriculum_version_id'], 'item_type' => 'multiple_choice', 'internal_label' => null, 'status' => 'draft', 'published_revision_id' => null]);
        $r = AssessmentItemRevision::query()->create(['assessment_item_id' => $i->id, 'curriculum_version_id' => $f['curriculum_version_id'], 'revision_number' => 1, 'primary_topic_id' => null, 'difficulty' => 'easy', 'content_payload' => ['x' => 1], 'content_schema_version' => 1, 'scoring_payload' => ['x' => 1], 'scoring_schema_version' => 1, 'released_at' => null]);
        $s = Skill::query()->create(['name' => 'h5'.Str::random(6), 'description' => null]);
        $p = SkillVersionPlacement::query()->create(['skill_id' => $s->id, 'curriculum_version_id' => $f['curriculum_version_id']]);
        AssessmentItemRevisionSkill::query()->create(['assessment_item_revision_id' => $r->id, 'skill_version_placement_id' => $p->id, 'curriculum_version_id' => $f['curriculum_version_id'], 'role' => 'primary']);
        DB::table('assessment_item_revisions')->where('id', $r->id)->update(['released_at' => now()]);

        return [
            'id' => $i->id,
            'item_id' => $i->id,
            'revision' => $r->id,
            'revision_id' => $r->id,
            'candidate_revision_number' => 2,
        ];
    }
}
