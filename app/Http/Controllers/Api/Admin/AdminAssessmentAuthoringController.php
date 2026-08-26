<?php

namespace App\Http\Controllers\Api\Admin;

use App\Application\Support\TransactionManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAssessmentItemRequest;
use App\Http\Requests\Admin\StoreAssessmentItemRevisionRequest;
use App\Http\Requests\Admin\StoreAssessmentItemRevisionSkillRequest;
use App\Http\Requests\Admin\UpdateAssessmentItemRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AssessmentItem;
use App\Models\AssessmentItemRevision;
use App\Models\AssessmentItemRevisionSkill;
use App\Models\CurriculumVersion;
use Illuminate\Http\JsonResponse;

class AdminAssessmentAuthoringController extends Controller
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function items(
        string $curriculumVersionId,
    ): JsonResponse {
        $version = CurriculumVersion::query()
            ->whereKey($curriculumVersionId)
            ->firstOrFail();

        $items = AssessmentItem::query()
            ->where(
                'curriculum_version_id',
                $version->id,
            )
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn (AssessmentItem $item): array =>
                    $this->itemData($item)
            )
            ->values()
            ->all();

        return ApiResponse::success($items);
    }

    public function storeItem(
        StoreAssessmentItemRequest $request,
        string $curriculumVersionId,
    ): JsonResponse {
        $version = CurriculumVersion::query()
            ->whereKey($curriculumVersionId)
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Assessment items may only be added to draft curriculum versions.',
                409,
            );
        }

        $item = $this->transactions->run(
            fn (): AssessmentItem =>
                AssessmentItem::query()->create([
                    'curriculum_version_id' =>
                        $version->id,
                    'item_type' =>
                        $request->validated(
                            'item_type'
                        ),
                    'internal_label' =>
                        $request->validated(
                            'internal_label'
                        ),
                    'status' => 'draft',
                    'published_revision_id' => null,
                ])
        );

        return ApiResponse::success(
            $this->itemData($item),
            201,
        );
    }

    public function updateItem(
        UpdateAssessmentItemRequest $request,
        string $assessmentItemId,
    ): JsonResponse {
        $item = AssessmentItem::query()
            ->whereKey($assessmentItemId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $item->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Assessment items may only be edited in draft curriculum versions.',
                409,
            );
        }

        if ($item->status !== 'draft') {
            return ApiResponse::error(
                'assessment_item_not_draft',
                'Only draft assessment items may be edited.',
                409,
            );
        }

        $this->transactions->run(
            fn (): bool => $item->update(
                $request->validated()
            )
        );

        return ApiResponse::success(
            $this->itemData(
                $item->refresh()
            )
        );
    }

    public function revisions(
        string $assessmentItemId,
    ): JsonResponse {
        $item = AssessmentItem::query()
            ->whereKey($assessmentItemId)
            ->firstOrFail();

        $revisions = AssessmentItemRevision::query()
            ->where(
                'assessment_item_id',
                $item->id,
            )
            ->orderBy('revision_number')
            ->orderBy('id')
            ->get()
            ->map(
                fn (
                    AssessmentItemRevision $revision
                ): array => $this->revisionData(
                    $revision
                )
            )
            ->values()
            ->all();

        return ApiResponse::success($revisions);
    }

    public function storeRevision(
        StoreAssessmentItemRevisionRequest $request,
        string $assessmentItemId,
    ): JsonResponse {
        $item = AssessmentItem::query()
            ->whereKey($assessmentItemId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $item->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Assessment item revisions may only be authored in draft curriculum versions.',
                409,
            );
        }

        if ($item->status !== 'draft') {
            return ApiResponse::error(
                'assessment_item_not_draft',
                'New revisions may only be authored for draft assessment items.',
                409,
            );
        }

        $revision = $this->transactions->run(
            fn (): AssessmentItemRevision =>
                AssessmentItemRevision::query()->create([
                    'assessment_item_id' =>
                        $item->id,
                    'curriculum_version_id' =>
                        $item->curriculum_version_id,
                    'revision_number' =>
                        $request->validated(
                            'revision_number'
                        ),
                    'primary_topic_id' =>
                        $request->validated(
                            'primary_topic_id'
                        ),
                    'difficulty' =>
                        $request->validated(
                            'difficulty'
                        ),
                    'content_payload' =>
                        $request->validated(
                            'content_payload'
                        ),
                    'content_schema_version' =>
                        $request->validated(
                            'content_schema_version'
                        ),
                    'scoring_payload' =>
                        $request->validated(
                            'scoring_payload'
                        ),
                    'scoring_schema_version' =>
                        $request->validated(
                            'scoring_schema_version'
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
        string $assessmentItemRevisionId,
    ): JsonResponse {
        $revision = AssessmentItemRevision::query()
            ->whereKey($assessmentItemRevisionId)
            ->firstOrFail();

        $classifications =
            AssessmentItemRevisionSkill::query()
                ->where(
                    'assessment_item_revision_id',
                    $revision->id,
                )
                ->with(
                    'skillVersionPlacement.skill'
                )
                ->orderBy('role')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(
                    fn (
                        AssessmentItemRevisionSkill
                        $classification
                    ): array =>
                        $this->revisionSkillData(
                            $classification
                        )
                )
                ->values()
                ->all();

        return ApiResponse::success(
            $classifications
        );
    }

    public function storeRevisionSkill(
        StoreAssessmentItemRevisionSkillRequest $request,
        string $assessmentItemRevisionId,
    ): JsonResponse {
        $revision = AssessmentItemRevision::query()
            ->whereKey($assessmentItemRevisionId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $revision->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Assessment revision classification may only be changed in draft curriculum versions.',
                409,
            );
        }

        if ($revision->released_at !== null) {
            return ApiResponse::error(
                'assessment_item_revision_released',
                'Released assessment item revisions are immutable.',
                409,
            );
        }

        $classification =
            $this->transactions->run(
                fn (): AssessmentItemRevisionSkill =>
                    AssessmentItemRevisionSkill::query()
                        ->create([
                            'assessment_item_revision_id' =>
                                $revision->id,
                            'skill_version_placement_id' =>
                                $request->validated(
                                    'skill_version_placement_id'
                                ),
                            'curriculum_version_id' =>
                                $revision
                                    ->curriculum_version_id,
                            'role' =>
                                $request->validated(
                                    'role'
                                ),
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
        string $assessmentItemRevisionId,
        string $assessmentItemRevisionSkillId,
    ): JsonResponse {
        $revision = AssessmentItemRevision::query()
            ->whereKey($assessmentItemRevisionId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $revision->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Assessment revision classification may only be changed in draft curriculum versions.',
                409,
            );
        }

        if ($revision->released_at !== null) {
            return ApiResponse::error(
                'assessment_item_revision_released',
                'Released assessment item revisions are immutable.',
                409,
            );
        }

        $classification =
            AssessmentItemRevisionSkill::query()
                ->whereKey(
                    $assessmentItemRevisionSkillId
                )
                ->where(
                    'assessment_item_revision_id',
                    $revision->id,
                )
                ->firstOrFail();

        $this->transactions->run(
            fn (): bool =>
                (bool) $classification->delete()
        );

        return ApiResponse::success([
            'id' =>
                $assessmentItemRevisionSkillId,
            'deleted' => true,
        ]);
    }

    private function revisionSkillData(
        AssessmentItemRevisionSkill $classification,
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
            'assessment_item_revision_id' =>
                $classification
                    ->assessment_item_revision_id,
            'skill_version_placement_id' =>
                $classification
                    ->skill_version_placement_id,
            'curriculum_version_id' =>
                $classification
                    ->curriculum_version_id,
            'role' => $classification->role,
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

    private function itemData(
        AssessmentItem $item,
    ): array {
        return [
            'id' => $item->id,
            'curriculum_version_id' =>
                $item->curriculum_version_id,
            'item_type' =>
                $item->item_type,
            'internal_label' =>
                $item->internal_label,
            'status' => $item->status,
            'published_revision_id' =>
                $item->published_revision_id,
            'created_at' =>
                $item->created_at?->toISOString(),
            'updated_at' =>
                $item->updated_at?->toISOString(),
        ];
    }

    private function revisionData(
        AssessmentItemRevision $revision,
    ): array {
        return [
            'id' => $revision->id,
            'assessment_item_id' =>
                $revision->assessment_item_id,
            'curriculum_version_id' =>
                $revision->curriculum_version_id,
            'revision_number' =>
                $revision->revision_number,
            'primary_topic_id' =>
                $revision->primary_topic_id,
            'difficulty' =>
                $revision->difficulty,
            'content_payload' =>
                $revision->content_payload,
            'content_schema_version' =>
                $revision->content_schema_version,
            'scoring_payload' =>
                $revision->scoring_payload,
            'scoring_schema_version' =>
                $revision->scoring_schema_version,
            'released_at' =>
                $revision->released_at?->toISOString(),
            'created_at' =>
                $revision->created_at?->toISOString(),
        ];
    }
}
