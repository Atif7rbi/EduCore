<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\Curriculum\PublishCurriculumVersion;
use App\Application\Curriculum\RetireCurriculumVersion;
use App\Application\Exceptions\CurriculumVersionNotReady;
use App\Application\Exceptions\TeacherAuthoringConflict;
use App\Application\TeacherAuthoring\FilterActiveTeacherCurriculumRead;
use App\Application\TeacherAuthoring\ManageTeacherCurriculumAuthoring;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\SkillHomeTopic;
use App\Models\SkillVersionPlacement;
use App\Models\Topic;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherCurriculumAuthoringController extends Controller
{
    public function __construct(
        private readonly CreateOwnedCurriculum $createCurriculum,
        private readonly ManageTeacherCurriculumAuthoring $authoring,
        private readonly FilterActiveTeacherCurriculumRead $reads,
        private readonly PublishCurriculumVersion $publishVersion,
        private readonly RetireCurriculumVersion $retireVersion,
    ) {}

    public function curricula(Request $request, string $assignmentId): JsonResponse
    {
        $curricula = $this->reads
            ->curricula(Curriculum::query(), $this->teacher($request)->id)
            ->where('curricula.teacher_subject_assignment_id', $assignmentId)
            ->orderBy('curricula.name')
            ->orderBy('curricula.id')
            ->get()
            ->map(fn (Curriculum $curriculum): array => $this->curriculumData($curriculum))
            ->values()
            ->all();

        return ApiResponse::success($curricula);
    }

    public function storeCurriculum(Request $request, string $assignmentId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'education_stage_id' => [
                'nullable',
                'uuid',
                Rule::exists('education_stages', 'id')->where('status', 'active'),
            ],
            'teacher_subject_assignment_id' => ['prohibited'],
            'subject_id' => ['prohibited'],
        ]);

        $curriculum = $this->createCurriculum->execute(
            actorUserId: $this->teacher($request)->id,
            teacherSubjectAssignmentId: $assignmentId,
            name: $validated['name'],
            educationStageId: $validated['education_stage_id'] ?? null,
        );

        return ApiResponse::success($this->curriculumData($curriculum), 201);
    }

    public function updateCurriculum(
        Request $request,
        string $assignmentId,
        string $curriculumId,
    ): JsonResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'education_stage_id' => ['prohibited'],
            'teacher_subject_assignment_id' => ['prohibited'],
            'subject_id' => ['prohibited'],
        ]);

        $curriculum = $this->authoring->updateCurriculum(
            $this->teacher($request)->id,
            $assignmentId,
            $curriculumId,
            $validated['name'],
        );

        return ApiResponse::success($this->curriculumData($curriculum));
    }

    public function versions(
        Request $request,
        string $assignmentId,
        string $curriculumId,
    ): JsonResponse {
        $this->authorizedCurriculum($request, $assignmentId, $curriculumId);

        $versions = CurriculumVersion::query()
            ->where('curriculum_id', $curriculumId)
            ->orderBy('version_number')
            ->orderBy('id')
            ->get()
            ->map(fn (CurriculumVersion $version): array => $this->versionData($version))
            ->values()
            ->all();

        return ApiResponse::success($versions);
    }

    public function storeVersion(
        Request $request,
        string $assignmentId,
        string $curriculumId,
    ): JsonResponse {
        $validated = $request->validate([
            'version_number' => ['required', 'integer', 'min:1'],
            'label' => ['required', 'string', 'max:255'],
            'status' => ['prohibited'],
            'curriculum_id' => ['prohibited'],
        ]);

        return $this->mutation(
            fn (): CurriculumVersion => $this->authoring->createVersion(
                $this->teacher($request)->id,
                $assignmentId,
                $curriculumId,
                $validated['version_number'],
                $validated['label'],
            ),
            fn (CurriculumVersion $version): array => $this->versionData($version),
            201,
        );
    }

    public function updateVersion(
        Request $request,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
    ): JsonResponse {
        $validated = $request->validate([
            'version_number' => ['required', 'integer', 'min:1'],
            'label' => ['required', 'string', 'max:255'],
            'status' => ['prohibited'],
            'curriculum_id' => ['prohibited'],
        ]);

        return $this->mutation(
            fn (): CurriculumVersion => $this->authoring->updateVersion(
                $this->teacher($request)->id,
                $assignmentId,
                $curriculumId,
                $versionId,
                $validated['version_number'],
                $validated['label'],
            ),
            fn (CurriculumVersion $version): array => $this->versionData($version),
        );
    }

    public function publishVersion(
        Request $request,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
    ): JsonResponse {
        return $this->mutation(
            fn (): CurriculumVersion => $this->publishVersion->execute(
                $versionId,
                fn (): string => $this->authoring->lockLifecycleAuthority(
                    $this->teacher($request)->id,
                    $assignmentId,
                    $curriculumId,
                ),
            ),
            fn (CurriculumVersion $version): array => $this->versionData($version),
        );
    }

    public function retireVersion(
        Request $request,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
    ): JsonResponse {
        return $this->mutation(
            fn (): CurriculumVersion => $this->retireVersion->execute(
                $versionId,
                fn (): string => $this->authoring->lockLifecycleAuthority(
                    $this->teacher($request)->id,
                    $assignmentId,
                    $curriculumId,
                ),
            ),
            fn (CurriculumVersion $version): array => $this->versionData($version),
        );
    }

    public function topics(
        Request $request,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
    ): JsonResponse {
        $this->authorizedVersion($request, $assignmentId, $curriculumId, $versionId);

        $topics = Topic::query()
            ->where('curriculum_version_id', $versionId)
            ->orderBy('display_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Topic $topic): array => $this->topicData($topic))
            ->values()
            ->all();

        return ApiResponse::success($topics);
    }

    public function storeTopic(
        Request $request,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
    ): JsonResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'curriculum_version_id' => ['prohibited'],
        ]);

        return $this->mutation(
            fn (): Topic => $this->authoring->createTopic(
                $this->teacher($request)->id,
                $assignmentId,
                $curriculumId,
                $versionId,
                $validated['name'],
                $validated['display_order'] ?? 0,
            ),
            fn (Topic $topic): array => $this->topicData($topic),
            201,
        );
    }

    public function updateTopic(
        Request $request,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
        string $topicId,
    ): JsonResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'display_order' => ['required', 'integer', 'min:0'],
            'curriculum_version_id' => ['prohibited'],
        ]);

        return $this->mutation(
            fn (): Topic => $this->authoring->updateTopic(
                $this->teacher($request)->id,
                $assignmentId,
                $curriculumId,
                $versionId,
                $topicId,
                $validated['name'],
                $validated['display_order'],
            ),
            fn (Topic $topic): array => $this->topicData($topic),
        );
    }

    public function placements(
        Request $request,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
    ): JsonResponse {
        $this->authorizedVersion($request, $assignmentId, $curriculumId, $versionId);

        $placements = SkillVersionPlacement::query()
            ->where('curriculum_version_id', $versionId)
            ->with(['skill', 'homeTopics.topic'])
            ->orderBy('id')
            ->get()
            ->map(fn (SkillVersionPlacement $placement): array => $this->placementData($placement))
            ->values()
            ->all();

        return ApiResponse::success($placements);
    }

    public function storePlacement(
        Request $request,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
    ): JsonResponse {
        $validated = $request->validate([
            'skill_id' => ['required', 'uuid', 'exists:skills,id'],
            'curriculum_version_id' => ['prohibited'],
        ]);

        return $this->mutation(
            function () use ($request, $assignmentId, $curriculumId, $versionId, $validated): SkillVersionPlacement {
                $placement = $this->authoring->createPlacement(
                    $this->teacher($request)->id,
                    $assignmentId,
                    $curriculumId,
                    $versionId,
                    $validated['skill_id'],
                );

                return $placement->load(['skill', 'homeTopics.topic']);
            },
            fn (SkillVersionPlacement $placement): array => $this->placementData($placement),
            201,
        );
    }

    public function destroyPlacement(
        Request $request,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
        string $placementId,
    ): JsonResponse {
        return $this->mutation(
            function () use ($request, $assignmentId, $curriculumId, $versionId, $placementId): array {
                $this->authoring->deletePlacement(
                    $this->teacher($request)->id,
                    $assignmentId,
                    $curriculumId,
                    $versionId,
                    $placementId,
                );

                return ['id' => $placementId, 'deleted' => true];
            },
            static fn (array $result): array => $result,
        );
    }

    public function storeHomeTopic(
        Request $request,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
        string $placementId,
    ): JsonResponse {
        $validated = $request->validate([
            'topic_id' => ['required', 'uuid'],
            'curriculum_version_id' => ['prohibited'],
        ]);

        return $this->mutation(
            function () use ($request, $assignmentId, $curriculumId, $versionId, $placementId, $validated): SkillHomeTopic {
                $homeTopic = $this->authoring->createHomeTopic(
                    $this->teacher($request)->id,
                    $assignmentId,
                    $curriculumId,
                    $versionId,
                    $placementId,
                    $validated['topic_id'],
                );

                return $homeTopic->load('topic');
            },
            fn (SkillHomeTopic $homeTopic): array => $this->homeTopicData($homeTopic),
            201,
        );
    }

    public function destroyHomeTopic(
        Request $request,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
        string $placementId,
        string $homeTopicId,
    ): JsonResponse {
        return $this->mutation(
            function () use ($request, $assignmentId, $curriculumId, $versionId, $placementId, $homeTopicId): array {
                $this->authoring->deleteHomeTopic(
                    $this->teacher($request)->id,
                    $assignmentId,
                    $curriculumId,
                    $versionId,
                    $placementId,
                    $homeTopicId,
                );

                return ['id' => $homeTopicId, 'deleted' => true];
            },
            static fn (array $result): array => $result,
        );
    }

    private function authorizedCurriculum(
        Request $request,
        string $assignmentId,
        string $curriculumId,
    ): Curriculum {
        return $this->reads
            ->curricula(Curriculum::query(), $this->teacher($request)->id)
            ->where('curricula.teacher_subject_assignment_id', $assignmentId)
            ->whereKey($curriculumId)
            ->firstOrFail();
    }

    private function authorizedVersion(
        Request $request,
        string $assignmentId,
        string $curriculumId,
        string $versionId,
    ): CurriculumVersion {
        return $this->reads
            ->versions(CurriculumVersion::query(), $this->teacher($request)->id)
            ->where('curriculum_versions.curriculum_id', $curriculumId)
            ->whereHas('curriculum', function (Builder $query) use ($assignmentId): void {
                $query->where('teacher_subject_assignment_id', $assignmentId);
            })
            ->whereKey($versionId)
            ->firstOrFail();
    }

    private function teacher(Request $request): User
    {
        /** @var User $teacher */
        $teacher = $request->user();

        return $teacher;
    }

    private function mutation(
        Closure $operation,
        Closure $serialize,
        int $status = 200,
    ): JsonResponse {
        try {
            $result = $operation();
        } catch (TeacherAuthoringConflict $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), 409);
        } catch (CurriculumVersionNotReady $exception) {
            return ApiResponse::error(
                'curriculum_version_not_ready',
                'The curriculum version does not satisfy the publishing requirements.',
                409,
                [
                    'blockers' => array_values(array_map(
                        static fn (array $blocker): string => $blocker['code'],
                        $exception->blockers,
                    )),
                ],
            );
        }

        return ApiResponse::success($serialize($result), $status);
    }

    private function curriculumData(Curriculum $curriculum): array
    {
        return [
            'id' => $curriculum->id,
            'subject_id' => $curriculum->subject_id,
            'education_stage_id' => $curriculum->education_stage_id,
            'teacher_subject_assignment_id' => $curriculum->teacher_subject_assignment_id,
            'name' => $curriculum->name,
        ];
    }

    private function versionData(CurriculumVersion $version): array
    {
        return [
            'id' => $version->id,
            'curriculum_id' => $version->curriculum_id,
            'version_number' => $version->version_number,
            'label' => $version->label,
            'status' => $version->status,
        ];
    }

    private function topicData(Topic $topic): array
    {
        return [
            'id' => $topic->id,
            'curriculum_version_id' => $topic->curriculum_version_id,
            'name' => $topic->name,
            'display_order' => $topic->display_order,
        ];
    }

    private function placementData(SkillVersionPlacement $placement): array
    {
        return [
            'id' => $placement->id,
            'skill_id' => $placement->skill_id,
            'curriculum_version_id' => $placement->curriculum_version_id,
            'skill' => $placement->relationLoaded('skill') ? [
                'id' => $placement->skill->id,
                'name' => $placement->skill->name,
            ] : null,
            'home_topics' => $placement->relationLoaded('homeTopics')
                ? $placement->homeTopics
                    ->map(fn (SkillHomeTopic $homeTopic): array => $this->homeTopicData($homeTopic))
                    ->values()
                    ->all()
                : [],
        ];
    }

    private function homeTopicData(SkillHomeTopic $homeTopic): array
    {
        return [
            'id' => $homeTopic->id,
            'placement_id' => $homeTopic->placement_id,
            'topic_id' => $homeTopic->topic_id,
            'curriculum_version_id' => $homeTopic->curriculum_version_id,
            'topic' => $homeTopic->relationLoaded('topic') ? [
                'id' => $homeTopic->topic->id,
                'name' => $homeTopic->topic->name,
            ] : null,
        ];
    }
}
