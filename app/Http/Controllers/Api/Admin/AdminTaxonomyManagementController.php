<?php

namespace App\Http\Controllers\Api\Admin;

use App\Application\Support\TransactionManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSkillRequest;
use App\Http\Requests\Admin\StoreSkillHomeTopicRequest;
use App\Http\Requests\Admin\StoreSkillPlacementRequest;
use App\Http\Requests\Admin\StoreTopicRequest;
use App\Http\Requests\Admin\UpdateSkillRequest;
use App\Http\Requests\Admin\UpdateTopicRequest;
use App\Http\Responses\ApiResponse;
use App\Models\CurriculumVersion;
use App\Models\Skill;
use App\Models\SkillHomeTopic;
use App\Models\SkillVersionPlacement;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;

class AdminTaxonomyManagementController extends Controller
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function topics(
        string $curriculumVersionId,
    ): JsonResponse {
        $version = CurriculumVersion::query()
            ->whereKey($curriculumVersionId)
            ->firstOrFail();

        $topics = Topic::query()
            ->where(
                'curriculum_version_id',
                $version->id,
            )
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
        StoreTopicRequest $request,
        string $curriculumVersionId,
    ): JsonResponse {
        $version = CurriculumVersion::query()
            ->whereKey($curriculumVersionId)
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Topics may only be added to draft curriculum versions.',
                409,
            );
        }

        $topic = $this->transactions->run(
            fn (): Topic => Topic::query()->create([
                'curriculum_version_id' => $version->id,
                'name' => $request->validated('name'),
                'display_order' =>
                    $request->validated('display_order') ?? 0,
            ])
        );

        return ApiResponse::success(
            $this->topicData($topic),
            201,
        );
    }

    public function updateTopic(
        UpdateTopicRequest $request,
        string $topicId,
    ): JsonResponse {
        $topic = Topic::query()
            ->whereKey($topicId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey($topic->curriculum_version_id)
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Topics may only be edited in draft curriculum versions.',
                409,
            );
        }

        $this->transactions->run(
            fn (): bool => $topic->update(
                $request->validated()
            )
        );

        return ApiResponse::success(
            $this->topicData(
                $topic->refresh()
            )
        );
    }

    public function skills(): JsonResponse
    {
        $skills = Skill::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Skill $skill): array => $this->skillData($skill))
            ->values()
            ->all();

        return ApiResponse::success($skills);
    }

    public function storeSkill(
        StoreSkillRequest $request,
    ): JsonResponse {
        $skill = $this->transactions->run(
            fn (): Skill => Skill::query()->create(
                $request->validated()
            )
        );

        return ApiResponse::success(
            $this->skillData($skill),
            201,
        );
    }

    public function updateSkill(
        UpdateSkillRequest $request,
        string $skillId,
    ): JsonResponse {
        $skill = Skill::query()
            ->whereKey($skillId)
            ->firstOrFail();

        $this->transactions->run(
            fn (): bool => $skill->update(
                $request->validated()
            )
        );

        return ApiResponse::success(
            $this->skillData(
                $skill->refresh()
            )
        );
    }

    public function placements(
        string $curriculumVersionId,
    ): JsonResponse {
        $version = CurriculumVersion::query()
            ->whereKey($curriculumVersionId)
            ->firstOrFail();

        $placements = SkillVersionPlacement::query()
            ->where(
                'curriculum_version_id',
                $version->id,
            )
            ->with([
                'skill',
                'homeTopics.topic',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn (
                    SkillVersionPlacement $placement
                ): array => $this->placementData(
                    $placement
                )
            )
            ->values()
            ->all();

        return ApiResponse::success($placements);
    }

    public function storePlacement(
        StoreSkillPlacementRequest $request,
        string $curriculumVersionId,
    ): JsonResponse {
        $version = CurriculumVersion::query()
            ->whereKey($curriculumVersionId)
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Skill placements may only be added to draft curriculum versions.',
                409,
            );
        }

        $placement = $this->transactions->run(
            fn (): SkillVersionPlacement =>
                SkillVersionPlacement::query()->create([
                    'skill_id' =>
                        $request->validated('skill_id'),
                    'curriculum_version_id' =>
                        $version->id,
                ])
        );

        $placement->load([
            'skill',
            'homeTopics.topic',
        ]);

        return ApiResponse::success(
            $this->placementData($placement),
            201,
        );
    }

    public function destroyPlacement(
        string $placementId,
    ): JsonResponse {
        $placement = SkillVersionPlacement::query()
            ->whereKey($placementId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $placement->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Skill placements may only be removed from draft curriculum versions.',
                409,
            );
        }

        $this->transactions->run(
            fn (): bool => (bool) $placement->delete()
        );

        return ApiResponse::success([
            'id' => $placementId,
            'deleted' => true,
        ]);
    }

    public function storeHomeTopic(
        StoreSkillHomeTopicRequest $request,
        string $placementId,
    ): JsonResponse {
        $placement = SkillVersionPlacement::query()
            ->whereKey($placementId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $placement->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Home Topics may only be changed in draft curriculum versions.',
                409,
            );
        }

        $homeTopic = $this->transactions->run(
            fn (): SkillHomeTopic =>
                SkillHomeTopic::query()->create([
                    'placement_id' =>
                        $placement->id,
                    'topic_id' =>
                        $request->validated('topic_id'),
                    'curriculum_version_id' =>
                        $placement->curriculum_version_id,
                ])
        );

        $homeTopic->load('topic');

        return ApiResponse::success(
            $this->homeTopicData($homeTopic),
            201,
        );
    }

    public function destroyHomeTopic(
        string $placementId,
        string $homeTopicId,
    ): JsonResponse {
        $placement = SkillVersionPlacement::query()
            ->whereKey($placementId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $placement->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Home Topics may only be changed in draft curriculum versions.',
                409,
            );
        }

        $homeTopic = SkillHomeTopic::query()
            ->whereKey($homeTopicId)
            ->where(
                'placement_id',
                $placement->id,
            )
            ->firstOrFail();

        $this->transactions->run(
            fn (): bool => (bool) $homeTopic->delete()
        );

        return ApiResponse::success([
            'id' => $homeTopicId,
            'deleted' => true,
        ]);
    }

    private function placementData(
        SkillVersionPlacement $placement,
    ): array {
        return [
            'id' => $placement->id,
            'skill_id' => $placement->skill_id,
            'curriculum_version_id' =>
                $placement->curriculum_version_id,
            'skill' => $placement->relationLoaded('skill')
                ? [
                    'id' => $placement->skill->id,
                    'name' => $placement->skill->name,
                ]
                : null,
            'home_topics' =>
                $placement->relationLoaded('homeTopics')
                    ? $placement->homeTopics
                        ->map(
                            fn (
                                SkillHomeTopic $homeTopic
                            ): array =>
                                $this->homeTopicData(
                                    $homeTopic
                                )
                        )
                        ->values()
                        ->all()
                    : [],
            'created_at' =>
                $placement->created_at?->toISOString(),
        ];
    }

    private function homeTopicData(
        SkillHomeTopic $homeTopic,
    ): array {
        return [
            'id' => $homeTopic->id,
            'placement_id' =>
                $homeTopic->placement_id,
            'topic_id' => $homeTopic->topic_id,
            'curriculum_version_id' =>
                $homeTopic->curriculum_version_id,
            'topic' => $homeTopic->relationLoaded('topic')
                ? [
                    'id' => $homeTopic->topic->id,
                    'name' => $homeTopic->topic->name,
                ]
                : null,
            'created_at' =>
                $homeTopic->created_at?->toISOString(),
        ];
    }

    private function topicData(
        Topic $topic,
    ): array {
        return [
            'id' => $topic->id,
            'curriculum_version_id' =>
                $topic->curriculum_version_id,
            'name' => $topic->name,
            'display_order' =>
                $topic->display_order,
            'created_at' =>
                $topic->created_at?->toISOString(),
            'updated_at' =>
                $topic->updated_at?->toISOString(),
        ];
    }

    private function skillData(
        Skill $skill,
    ): array {
        return [
            'id' => $skill->id,
            'name' => $skill->name,
            'description' => $skill->description,
            'created_at' =>
                $skill->created_at?->toISOString(),
            'updated_at' =>
                $skill->updated_at?->toISOString(),
        ];
    }
}
