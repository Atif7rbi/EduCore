<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Application\Assessment\PublishAssessmentItem;
use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Assessment\RetireAssessmentItem;
use App\Application\Exceptions\TeacherAuthoringConflict;
use App\Application\Learning\PublishLesson;
use App\Application\Learning\ReleaseLessonRevision;
use App\Application\Learning\UnpublishLesson;
use App\Application\TeacherAuthoring\FilterActiveTeacherCurriculumRead;
use App\Application\TeacherAuthoring\ManageTeacherLessonAssessmentAuthoring;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AssessmentItem;
use App\Models\AssessmentItemRevision;
use App\Models\AssessmentItemRevisionSkill;
use App\Models\CurriculumVersion;
use App\Models\Lesson;
use App\Models\LessonRevision;
use App\Models\LessonRevisionSkill;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherLessonAssessmentAuthoringController extends Controller
{
    public function __construct(
        private readonly ManageTeacherLessonAssessmentAuthoring $authoring,
        private readonly FilterActiveTeacherCurriculumRead $reads,
        private readonly PublishLesson $publishLesson,
        private readonly UnpublishLesson $unpublishLesson,
        private readonly ReleaseLessonRevision $releaseLessonRevision,
        private readonly PublishAssessmentItem $publishAssessment,
        private readonly RetireAssessmentItem $retireAssessment,
        private readonly ReleaseAssessmentItemRevision $releaseAssessmentRevision,
    ) {}

    public function lessons(Request $request, string $assignmentId, string $curriculumId, string $versionId): JsonResponse
    {
        $v = $this->version($request, $assignmentId, $curriculumId, $versionId);

        return ApiResponse::success(Lesson::query()->where('curriculum_version_id', $v->id)->orderBy('display_order')->orderBy('title')->orderBy('id')->get()->map(fn (Lesson $x) => $this->lesson($x))->values());
    }

    public function storeLesson(Request $r, string $a, string $c, string $v): JsonResponse
    {
        $x = $r->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'display_order' => ['sometimes', 'integer', 'min:0'], 'curriculum_version_id' => ['prohibited']]);

        return $this->mutate(fn () => $this->authoring->createLesson($this->teacher($r)->id, $a, $c, $v, $x['title'], $x['description'] ?? null, $x['display_order'] ?? 0), fn (Lesson $l) => $this->lesson($l), 201);
    }

    public function updateLesson(Request $r, string $a, string $c, string $v, string $lesson): JsonResponse
    {
        $x = $r->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'display_order' => ['required', 'integer', 'min:0'], 'curriculum_version_id' => ['prohibited'], 'status' => ['prohibited'], 'published_revision_id' => ['prohibited']]);

        return $this->mutate(fn () => $this->authoring->updateLesson($this->teacher($r)->id, $a, $c, $v, $lesson, $x['title'], $x['description'] ?? null, $x['display_order']), fn (Lesson $l) => $this->lesson($l));
    }

    public function lessonRevisions(Request $r, string $a, string $c, string $v, string $lesson): JsonResponse
    {
        $version = $this->version($r, $a, $c, $v);
        $this->readLesson($lesson, $version);

        return ApiResponse::success(LessonRevision::query()->where('lesson_id', $lesson)->where('curriculum_version_id', $v)->orderBy('revision_number')->orderBy('id')->get()->map(fn (LessonRevision $x) => $this->lessonRevision($x))->values());
    }

    public function storeLessonRevision(Request $r, string $a, string $c, string $v, string $lesson): JsonResponse
    {
        $x = $this->lessonRevisionInput($r);

        return $this->mutate(fn () => $this->authoring->createLessonRevision($this->teacher($r)->id, $a, $c, $v, $lesson, $x['revision_number'], $x['primary_topic_id'], $x['content_payload'], $x['content_schema_version']), fn (LessonRevision $z) => $this->lessonRevision($z), 201);
    }

    public function updateLessonRevision(Request $r, string $a, string $c, string $v, string $lesson, string $revision): JsonResponse
    {
        $x = $this->lessonRevisionInput($r);

        return $this->mutate(fn () => $this->authoring->updateLessonRevision($this->teacher($r)->id, $a, $c, $v, $lesson, $revision, $x['revision_number'], $x['primary_topic_id'], $x['content_payload'], $x['content_schema_version']), fn (LessonRevision $z) => $this->lessonRevision($z));
    }

    public function lessonRevisionSkills(Request $r, string $a, string $c, string $v, string $lesson, string $revision): JsonResponse
    {
        $version = $this->version($r, $a, $c, $v);
        $this->readLessonRevision($revision, $this->readLesson($lesson, $version), $version);

        return ApiResponse::success(LessonRevisionSkill::query()->where('lesson_revision_id', $revision)->where('curriculum_version_id', $v)->with('skillVersionPlacement.skill')->orderBy('id')->get()->map(fn (LessonRevisionSkill $x) => $this->lessonSkill($x))->values());
    }

    public function storeLessonRevisionSkill(Request $r, string $a, string $c, string $v, string $lesson, string $revision): JsonResponse
    {
        $x = $r->validate(['skill_version_placement_id' => ['required', 'uuid']]);

        return $this->mutate(fn () => $this->authoring->createLessonRevisionSkill($this->teacher($r)->id, $a, $c, $v, $lesson, $revision, $x['skill_version_placement_id']), fn (LessonRevisionSkill $z) => $this->lessonSkill($z->load('skillVersionPlacement.skill')), 201);
    }

    public function destroyLessonRevisionSkill(Request $r, string $a, string $c, string $v, string $lesson, string $revision, string $skill): JsonResponse
    {
        return $this->mutate(function () use ($r, $a, $c, $v, $lesson, $revision, $skill) {
            $this->authoring->deleteLessonRevisionSkill($this->teacher($r)->id, $a, $c, $v, $lesson, $revision, $skill);

            return ['id' => $skill, 'deleted' => true];
        }, static fn (array $x) => $x);
    }

    public function releaseLessonRevision(Request $r, string $a, string $c, string $v, string $lesson, string $revision): JsonResponse
    {
        return $this->mutate(fn () => $this->releaseLessonRevision->execute($revision, fn () => $this->authoring->lockLessonLifecycle($this->teacher($r)->id, $a, $c, $v, $lesson, $revision)), fn (LessonRevision $z) => $this->lessonRevision($z));
    }

    public function publishLesson(Request $r, string $a, string $c, string $v, string $lesson): JsonResponse
    {
        $x = $r->validate(['published_revision_id' => ['required', 'uuid']]);

        return $this->mutate(fn () => $this->publishLesson->execute($lesson, $x['published_revision_id'], fn () => $this->authoring->lockLessonLifecycle($this->teacher($r)->id, $a, $c, $v, $lesson, $x['published_revision_id'])), fn (Lesson $z) => $this->lesson($z));
    }

    public function unpublishLesson(Request $r, string $a, string $c, string $v, string $lesson): JsonResponse
    {
        return $this->mutate(fn () => $this->unpublishLesson->execute($lesson, fn () => $this->authoring->lockLessonLifecycle($this->teacher($r)->id, $a, $c, $v, $lesson)), fn (Lesson $z) => $this->lesson($z));
    }

    public function assessments(Request $r, string $a, string $c, string $v): JsonResponse
    {
        $z = $this->version($r, $a, $c, $v);

        return ApiResponse::success(AssessmentItem::query()->where('curriculum_version_id', $z->id)->orderBy('id')->get()->map(fn (AssessmentItem $x) => $this->assessment($x))->values());
    }

    public function storeAssessment(Request $r, string $a, string $c, string $v): JsonResponse
    {
        $x = $r->validate(['item_type' => ['required', 'string', 'max:255'], 'internal_label' => ['nullable', 'string', 'max:255'], 'curriculum_version_id' => ['prohibited']]);

        return $this->mutate(fn () => $this->authoring->createAssessment($this->teacher($r)->id, $a, $c, $v, $x['item_type'], $x['internal_label'] ?? null), fn (AssessmentItem $z) => $this->assessment($z), 201);
    }

    public function updateAssessment(Request $r, string $a, string $c, string $v, string $item): JsonResponse
    {
        $x = $r->validate(['item_type' => ['required', 'string', 'max:255'], 'internal_label' => ['nullable', 'string', 'max:255'], 'curriculum_version_id' => ['prohibited'], 'status' => ['prohibited'], 'published_revision_id' => ['prohibited']]);

        return $this->mutate(fn () => $this->authoring->updateAssessment($this->teacher($r)->id, $a, $c, $v, $item, $x['item_type'], $x['internal_label'] ?? null), fn (AssessmentItem $z) => $this->assessment($z));
    }

    public function assessmentRevisions(Request $r, string $a, string $c, string $v, string $item): JsonResponse
    {
        $version = $this->version($r, $a, $c, $v);
        $this->readAssessment($item, $version);

        return ApiResponse::success(AssessmentItemRevision::query()->where('assessment_item_id', $item)->where('curriculum_version_id', $v)->orderBy('revision_number')->orderBy('id')->get()->map(fn (AssessmentItemRevision $x) => $this->assessmentRevision($x))->values());
    }

    public function storeAssessmentRevision(Request $r, string $a, string $c, string $v, string $item): JsonResponse
    {
        $x = $this->assessmentRevisionInput($r);

        return $this->mutate(fn () => $this->authoring->createAssessmentRevision($this->teacher($r)->id, $a, $c, $v, $item, ...$x), fn (AssessmentItemRevision $z) => $this->assessmentRevision($z), 201);
    }

    public function updateAssessmentRevision(Request $r, string $a, string $c, string $v, string $item, string $revision): JsonResponse
    {
        $x = $this->assessmentRevisionInput($r);

        return $this->mutate(fn () => $this->authoring->updateAssessmentRevision($this->teacher($r)->id, $a, $c, $v, $item, $revision, ...$x), fn (AssessmentItemRevision $z) => $this->assessmentRevision($z));
    }

    public function assessmentRevisionSkills(Request $r, string $a, string $c, string $v, string $item, string $revision): JsonResponse
    {
        $version = $this->version($r, $a, $c, $v);
        $this->readAssessmentRevision($revision, $this->readAssessment($item, $version), $version);

        return ApiResponse::success(AssessmentItemRevisionSkill::query()->where('assessment_item_revision_id', $revision)->where('curriculum_version_id', $v)->with('skillVersionPlacement.skill')->orderBy('role')->orderBy('id')->get()->map(fn (AssessmentItemRevisionSkill $x) => $this->assessmentSkill($x))->values());
    }

    public function storeAssessmentRevisionSkill(Request $r, string $a, string $c, string $v, string $item, string $revision): JsonResponse
    {
        $x = $r->validate(['skill_version_placement_id' => ['required', 'uuid'], 'role' => ['required', 'in:primary,supporting']]);

        return $this->mutate(fn () => $this->authoring->createAssessmentRevisionSkill($this->teacher($r)->id, $a, $c, $v, $item, $revision, $x['skill_version_placement_id'], $x['role']), fn (AssessmentItemRevisionSkill $z) => $this->assessmentSkill($z->load('skillVersionPlacement.skill')), 201);
    }

    public function destroyAssessmentRevisionSkill(Request $r, string $a, string $c, string $v, string $item, string $revision, string $skill): JsonResponse
    {
        return $this->mutate(function () use ($r, $a, $c, $v, $item, $revision, $skill) {
            $this->authoring->deleteAssessmentRevisionSkill($this->teacher($r)->id, $a, $c, $v, $item, $revision, $skill);

            return ['id' => $skill, 'deleted' => true];
        }, static fn (array $x) => $x);
    }

    public function releaseAssessmentRevision(Request $r, string $a, string $c, string $v, string $item, string $revision): JsonResponse
    {
        return $this->mutate(fn () => $this->releaseAssessmentRevision->execute($revision, fn () => $this->authoring->lockAssessmentLifecycle($this->teacher($r)->id, $a, $c, $v, $item, $revision)), fn (AssessmentItemRevision $z) => $this->assessmentRevision($z));
    }

    public function publishAssessment(Request $r, string $a, string $c, string $v, string $item): JsonResponse
    {
        $x = $r->validate(['published_revision_id' => ['required', 'uuid']]);

        return $this->mutate(fn () => $this->publishAssessment->execute($item, $x['published_revision_id'], fn () => $this->authoring->lockAssessmentLifecycle($this->teacher($r)->id, $a, $c, $v, $item, $x['published_revision_id'])), fn (AssessmentItem $z) => $this->assessment($z));
    }

    public function retireAssessment(Request $r, string $a, string $c, string $v, string $item): JsonResponse
    {
        return $this->mutate(fn () => $this->retireAssessment->execute($item, fn () => $this->authoring->lockAssessmentLifecycle($this->teacher($r)->id, $a, $c, $v, $item)), fn (AssessmentItem $z) => $this->assessment($z));
    }

    private function version(Request $r, string $a, string $c, string $v): CurriculumVersion
    {
        return $this->reads->versions(CurriculumVersion::query(), $this->teacher($r)->id)->where('curriculum_versions.curriculum_id', $c)->whereHas('curriculum', fn (Builder $q) => $q->where('teacher_subject_assignment_id', $a))->whereKey($v)->firstOrFail();
    }

    private function readLesson(string $lessonId, CurriculumVersion $version): Lesson
    {
        return Lesson::query()
            ->whereKey($lessonId)
            ->where('curriculum_version_id', $version->id)
            ->firstOrFail();
    }

    private function readLessonRevision(string $revisionId, Lesson $lesson, CurriculumVersion $version): LessonRevision
    {
        return LessonRevision::query()
            ->whereKey($revisionId)
            ->where('lesson_id', $lesson->id)
            ->where('curriculum_version_id', $version->id)
            ->firstOrFail();
    }

    private function readAssessment(string $itemId, CurriculumVersion $version): AssessmentItem
    {
        return AssessmentItem::query()
            ->whereKey($itemId)
            ->where('curriculum_version_id', $version->id)
            ->firstOrFail();
    }

    private function readAssessmentRevision(string $revisionId, AssessmentItem $item, CurriculumVersion $version): AssessmentItemRevision
    {
        return AssessmentItemRevision::query()
            ->whereKey($revisionId)
            ->where('assessment_item_id', $item->id)
            ->where('curriculum_version_id', $version->id)
            ->firstOrFail();
    }

    private function teacher(Request $r): User
    {
        return $r->user();
    }

    private function mutate(Closure $f, Closure $out, int $status = 200): JsonResponse
    {
        try {
            $z = $f();
        } catch (TeacherAuthoringConflict $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), 409);
        }

        return ApiResponse::success($out($z), $status);
    }

    private function lessonRevisionInput(Request $r): array
    {
        return $r->validate(['revision_number' => ['required', 'integer', 'min:1'], 'primary_topic_id' => ['required', 'uuid'], 'content_payload' => ['required', 'array'], 'content_schema_version' => ['required', 'integer', 'min:1']]);
    }

    private function assessmentRevisionInput(Request $r): array
    {
        $x = $r->validate(['revision_number' => ['required', 'integer', 'min:1'], 'primary_topic_id' => ['nullable', 'uuid'], 'difficulty' => ['required', 'in:easy,medium,hard'], 'content_payload' => ['required', 'array'], 'content_schema_version' => ['required', 'integer', 'min:1'], 'scoring_payload' => ['required', 'array'], 'scoring_schema_version' => ['required', 'integer', 'min:1']]);

        return [$x['revision_number'], $x['primary_topic_id'] ?? null, $x['difficulty'], $x['content_payload'], $x['content_schema_version'], $x['scoring_payload'], $x['scoring_schema_version']];
    }

    private function lesson(Lesson $x): array
    {
        return ['id' => $x->id, 'curriculum_version_id' => $x->curriculum_version_id, 'title' => $x->title, 'description' => $x->description, 'status' => $x->status, 'display_order' => $x->display_order, 'published_revision_id' => $x->published_revision_id];
    }

    private function lessonRevision(LessonRevision $x): array
    {
        return ['id' => $x->id, 'lesson_id' => $x->lesson_id, 'curriculum_version_id' => $x->curriculum_version_id, 'revision_number' => $x->revision_number, 'primary_topic_id' => $x->primary_topic_id, 'content_payload' => $x->content_payload, 'content_schema_version' => $x->content_schema_version, 'released_at' => $x->released_at?->toISOString()];
    }

    private function lessonSkill(LessonRevisionSkill $x): array
    {
        return ['id' => $x->id, 'lesson_revision_id' => $x->lesson_revision_id, 'skill_version_placement_id' => $x->skill_version_placement_id, 'curriculum_version_id' => $x->curriculum_version_id];
    }

    private function assessment(AssessmentItem $x): array
    {
        return ['id' => $x->id, 'curriculum_version_id' => $x->curriculum_version_id, 'item_type' => $x->item_type, 'internal_label' => $x->internal_label, 'status' => $x->status, 'published_revision_id' => $x->published_revision_id];
    }

    private function assessmentRevision(AssessmentItemRevision $x): array
    {
        return ['id' => $x->id, 'assessment_item_id' => $x->assessment_item_id, 'curriculum_version_id' => $x->curriculum_version_id, 'revision_number' => $x->revision_number, 'primary_topic_id' => $x->primary_topic_id, 'difficulty' => $x->difficulty, 'content_payload' => $x->content_payload, 'content_schema_version' => $x->content_schema_version, 'scoring_payload' => $x->scoring_payload, 'scoring_schema_version' => $x->scoring_schema_version, 'released_at' => $x->released_at?->toISOString()];
    }

    private function assessmentSkill(AssessmentItemRevisionSkill $x): array
    {
        return ['id' => $x->id, 'assessment_item_revision_id' => $x->assessment_item_revision_id, 'skill_version_placement_id' => $x->skill_version_placement_id, 'curriculum_version_id' => $x->curriculum_version_id, 'role' => $x->role];
    }
}
