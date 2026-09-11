<?php

namespace App\Http\Controllers\Api\Admin;

use App\Application\Support\TransactionManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLessonRequest;
use App\Http\Requests\Admin\StoreLessonRevisionRequest;
use App\Http\Requests\Admin\StoreLessonRevisionSkillRequest;
use App\Http\Requests\Admin\UpdateLessonRequest;
use App\Http\Responses\ApiResponse;
use App\Models\CurriculumVersion;
use App\Models\Lesson;
use App\Models\LessonRevision;
use App\Models\LessonRevisionSkill;
use Illuminate\Http\JsonResponse;

class AdminLessonAuthoringController extends Controller
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function lessons(
        string $curriculumVersionId,
    ): JsonResponse {
        $version = CurriculumVersion::query()
            ->whereKey($curriculumVersionId)
            ->firstOrFail();

        $lessons = Lesson::query()
            ->where(
                'curriculum_version_id',
                $version->id,
            )
            ->orderBy('display_order')
            ->orderBy('title')
            ->orderBy('id')
            ->get()
            ->map(
                fn (Lesson $lesson): array =>
                    $this->lessonData($lesson)
            )
            ->values()
            ->all();

        return ApiResponse::success($lessons);
    }

    public function storeLesson(
        StoreLessonRequest $request,
        string $curriculumVersionId,
    ): JsonResponse {
        $version = CurriculumVersion::query()
            ->whereKey($curriculumVersionId)
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Lessons may only be added to draft curriculum versions.',
                409,
            );
        }

        $lesson = $this->transactions->run(
            fn (): Lesson => Lesson::query()->create([
                'curriculum_version_id' =>
                    $version->id,
                'title' =>
                    $request->validated('title'),
                'description' =>
                    $request->validated('description'),
                'status' => 'draft',
                'display_order' =>
                    $request->validated('display_order') ?? 0,
                'published_revision_id' => null,
            ])
        );

        return ApiResponse::success(
            $this->lessonData($lesson),
            201,
        );
    }

    public function updateLesson(
        UpdateLessonRequest $request,
        string $lessonId,
    ): JsonResponse {
        $lesson = Lesson::query()
            ->whereKey($lessonId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $lesson->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Lessons may only be edited in draft curriculum versions.',
                409,
            );
        }

        if ($lesson->status === 'retired') {
            return ApiResponse::error(
                'lesson_retired',
                'Retired lessons may not be edited.',
                409,
            );
        }

        $this->transactions->run(
            fn (): bool => $lesson->update(
                $request->validated()
            )
        );

        return ApiResponse::success(
            $this->lessonData(
                $lesson->refresh()
            )
        );
    }

    public function revisions(
        string $lessonId,
    ): JsonResponse {
        $lesson = Lesson::query()
            ->whereKey($lessonId)
            ->firstOrFail();

        $revisions = LessonRevision::query()
            ->where(
                'lesson_id',
                $lesson->id,
            )
            ->orderBy('revision_number')
            ->orderBy('id')
            ->get()
            ->map(
                fn (
                    LessonRevision $revision
                ): array => $this->revisionData(
                    $revision
                )
            )
            ->values()
            ->all();

        return ApiResponse::success($revisions);
    }

    public function storeRevision(
        StoreLessonRevisionRequest $request,
        string $lessonId,
    ): JsonResponse {
        $lesson = Lesson::query()
            ->whereKey($lessonId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $lesson->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Lesson revisions may only be authored in draft curriculum versions.',
                409,
            );
        }

        if ($lesson->status === 'retired') {
            return ApiResponse::error(
                'lesson_retired',
                'New revisions may not be authored for retired lessons.',
                409,
            );
        }

        $revision = $this->transactions->run(
            fn (): LessonRevision =>
                LessonRevision::query()->create([
                    'lesson_id' =>
                        $lesson->id,
                    'curriculum_version_id' =>
                        $lesson->curriculum_version_id,
                    'revision_number' =>
                        $request->validated(
                            'revision_number'
                        ),
                    'primary_topic_id' =>
                        $request->validated(
                            'primary_topic_id'
                        ),
                    'content_payload' =>
                        $request->validated(
                            'content_payload'
                        ),
                    'content_schema_version' =>
                        $request->validated(
                            'content_schema_version'
                        ),
                    'released_at' => null,
                ])
        );

        return ApiResponse::success(
            $this->revisionData($revision),
            201,
        );
    }

    public function revisionSkills(
        string $lessonRevisionId,
    ): JsonResponse {
        $revision = LessonRevision::query()
            ->whereKey($lessonRevisionId)
            ->firstOrFail();

        $skills = LessonRevisionSkill::query()
            ->where(
                'lesson_revision_id',
                $revision->id,
            )
            ->with('skillVersionPlacement.skill')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn (
                    LessonRevisionSkill $classification
                ): array => $this->revisionSkillData(
                    $classification
                )
            )
            ->values()
            ->all();

        return ApiResponse::success($skills);
    }

    public function storeRevisionSkill(
        StoreLessonRevisionSkillRequest $request,
        string $lessonRevisionId,
    ): JsonResponse {
        $revision = LessonRevision::query()
            ->whereKey($lessonRevisionId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $revision->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Lesson revision classification may only be changed in draft curriculum versions.',
                409,
            );
        }

        if ($revision->released_at !== null) {
            return ApiResponse::error(
                'lesson_revision_released',
                'Released lesson revisions are immutable.',
                409,
            );
        }

        $classification = $this->transactions->run(
            fn (): LessonRevisionSkill =>
                LessonRevisionSkill::query()->create([
                    'lesson_revision_id' =>
                        $revision->id,
                    'skill_version_placement_id' =>
                        $request->validated(
                            'skill_version_placement_id'
                        ),
                    'curriculum_version_id' =>
                        $revision->curriculum_version_id,
                ])
        );

        $classification->load(
            'skillVersionPlacement.skill'
        );

        return ApiResponse::success(
            $this->revisionSkillData(
                $classification
            ),
            201,
        );
    }

    public function destroyRevisionSkill(
        string $lessonRevisionId,
        string $lessonRevisionSkillId,
    ): JsonResponse {
        $revision = LessonRevision::query()
            ->whereKey($lessonRevisionId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $revision->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Lesson revision classification may only be changed in draft curriculum versions.',
                409,
            );
        }

        if ($revision->released_at !== null) {
            return ApiResponse::error(
                'lesson_revision_released',
                'Released lesson revisions are immutable.',
                409,
            );
        }

        $classification =
            LessonRevisionSkill::query()
                ->whereKey(
                    $lessonRevisionSkillId
                )
                ->where(
                    'lesson_revision_id',
                    $revision->id,
                )
                ->firstOrFail();

        $this->transactions->run(
            fn (): bool =>
                (bool) $classification->delete()
        );

        return ApiResponse::success([
            'id' => $lessonRevisionSkillId,
            'deleted' => true,
        ]);
    }

    private function revisionSkillData(
        LessonRevisionSkill $classification,
    ): array {
        $placement =
            $classification->relationLoaded(
                'skillVersionPlacement'
            )
                ? $classification
                    ->skillVersionPlacement
                : null;

        return [
            'id' => $classification->id,
            'lesson_revision_id' =>
                $classification
                    ->lesson_revision_id,
            'skill_version_placement_id' =>
                $classification
                    ->skill_version_placement_id,
            'curriculum_version_id' =>
                $classification
                    ->curriculum_version_id,
            'skill' =>
                $placement !== null
                && $placement->relationLoaded(
                    'skill'
                )
                    ? [
                        'id' =>
                            $placement->skill->id,
                        'name' =>
                            $placement->skill->name,
                    ]
                    : null,
            'created_at' =>
                $classification
                    ->created_at?->toISOString(),
        ];
    }

    private function lessonData(
        Lesson $lesson,
    ): array {
        return [
            'id' => $lesson->id,
            'curriculum_version_id' =>
                $lesson->curriculum_version_id,
            'title' => $lesson->title,
            'description' =>
                $lesson->description,
            'status' => $lesson->status,
            'display_order' =>
                $lesson->display_order,
            'published_revision_id' =>
                $lesson->published_revision_id,
            'created_at' =>
                $lesson->created_at?->toISOString(),
            'updated_at' =>
                $lesson->updated_at?->toISOString(),
        ];
    }

    private function revisionData(
        LessonRevision $revision,
    ): array {
        return [
            'id' => $revision->id,
            'lesson_id' =>
                $revision->lesson_id,
            'curriculum_version_id' =>
                $revision->curriculum_version_id,
            'revision_number' =>
                $revision->revision_number,
            'primary_topic_id' =>
                $revision->primary_topic_id,
            'content_payload' =>
                $revision->content_payload,
            'content_schema_version' =>
                $revision->content_schema_version,
            'released_at' =>
                $revision->released_at?->toISOString(),
            'created_at' =>
                $revision->created_at?->toISOString(),
        ];
    }
}
