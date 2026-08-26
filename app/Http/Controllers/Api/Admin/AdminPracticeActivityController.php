<?php

namespace App\Http\Controllers\Api\Admin;

use App\Application\Practice\AddPracticeActivityItem;
use App\Application\Practice\RemovePracticeActivityItem;
use App\Application\Support\TransactionManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePracticeActivityRequest;
use App\Http\Requests\Admin\StorePracticeActivityItemRequest;
use App\Http\Requests\Admin\UpdatePracticeActivityRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AssessmentItemRevision;
use App\Models\CurriculumVersion;
use App\Models\PracticeActivity;
use App\Models\PracticeActivityItem;
use Illuminate\Http\JsonResponse;

class AdminPracticeActivityController extends Controller
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AddPracticeActivityItem $addItem,
        private readonly RemovePracticeActivityItem $removeItem,
    ) {
    }

    public function index(
        string $curriculumVersionId,
    ): JsonResponse {
        $version = CurriculumVersion::query()
            ->whereKey($curriculumVersionId)
            ->firstOrFail();

        $activities = PracticeActivity::query()
            ->where(
                'curriculum_version_id',
                $version->id,
            )
            ->withCount('items')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(
                fn (
                    PracticeActivity $activity
                ): array => $this->activityData(
                    $activity
                )
            )
            ->values()
            ->all();

        return ApiResponse::success($activities);
    }

    public function store(
        StorePracticeActivityRequest $request,
        string $curriculumVersionId,
    ): JsonResponse {
        $version = CurriculumVersion::query()
            ->whereKey($curriculumVersionId)
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Practice activities may only be authored in draft curriculum versions.',
                409,
            );
        }

        $activity = $this->transactions->run(
            fn (): PracticeActivity =>
                PracticeActivity::query()->create([
                    'curriculum_version_id' =>
                        $version->id,
                    'lesson_id' =>
                        $request->validated(
                            'lesson_id'
                        ),
                    'name' =>
                        $request->validated(
                            'name'
                        ),
                    'description' =>
                        $request->validated(
                            'description'
                        ),
                    'status' => 'archived',
                ])
        );

        $activity->loadCount('items');

        return ApiResponse::success(
            $this->activityData($activity),
            201,
        );
    }

    public function update(
        UpdatePracticeActivityRequest $request,
        string $practiceActivityId,
    ): JsonResponse {
        $activity = PracticeActivity::query()
            ->whereKey($practiceActivityId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $activity->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Practice activities may only be edited in draft curriculum versions.',
                409,
            );
        }

        if ($activity->status !== 'archived') {
            return ApiResponse::error(
                'practice_activity_not_archived',
                'Only archived practice activities may be edited.',
                409,
            );
        }

        $this->transactions->run(
            fn (): bool => $activity->update(
                $request->validated()
            )
        );

        $activity->refresh()->loadCount('items');

        return ApiResponse::success(
            $this->activityData($activity)
        );
    }

    public function activate(
        string $practiceActivityId,
    ): JsonResponse {
        $activity = PracticeActivity::query()
            ->whereKey($practiceActivityId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $activity->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Practice activities may only be activated in draft curriculum versions.',
                409,
            );
        }

        if ($activity->status === 'active') {
            $activity->loadCount('items');

            return ApiResponse::success(
                $this->activityData($activity)
            );
        }

        $activationResult = $this->transactions->run(
            function () use ($activity): string {
                $lockedActivity =
                    PracticeActivity::query()
                        ->whereKey($activity->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                if (! $lockedActivity->items()->exists()) {
                    return 'empty';
                }

                $containsUnreleasedRevision =
                    $lockedActivity->items()
                        ->whereHas(
                            'assessmentItemRevision',
                            fn ($query) =>
                                $query->whereNull(
                                    'released_at'
                                )
                        )
                        ->exists();

                if ($containsUnreleasedRevision) {
                    return 'unreleased';
                }

                PracticeActivity::query()
                    ->whereKey($lockedActivity->id)
                    ->update([
                        'status' => 'active',
                        'updated_at' => now(),
                    ]);

                return 'activated';
            }
        );

        if ($activationResult === 'empty') {
            return ApiResponse::error(
                'practice_activity_empty',
                'An active practice activity requires at least one item.',
                409,
            );
        }

        if ($activationResult === 'unreleased') {
            return ApiResponse::error(
                'practice_activity_contains_unreleased_revision',
                'An active practice activity may only contain released assessment item revisions.',
                409,
            );
        }

        $activity->refresh()->loadCount('items');

        return ApiResponse::success(
            $this->activityData($activity)
        );
    }

    public function archive(
        string $practiceActivityId,
    ): JsonResponse {
        $activity = PracticeActivity::query()
            ->whereKey($practiceActivityId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $activity->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Practice activities may only be archived in draft curriculum versions.',
                409,
            );
        }

        if ($activity->status === 'archived') {
            $activity->loadCount('items');

            return ApiResponse::success(
                $this->activityData($activity)
            );
        }

        $this->transactions->run(
            function () use ($activity): void {
                PracticeActivity::query()
                    ->whereKey($activity->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                PracticeActivity::query()
                    ->whereKey($activity->id)
                    ->update([
                        'status' => 'archived',
                        'updated_at' => now(),
                    ]);
            }
        );

        $activity->refresh()->loadCount('items');

        return ApiResponse::success(
            $this->activityData($activity)
        );
    }

    public function items(
        string $practiceActivityId,
    ): JsonResponse {
        $activity = PracticeActivity::query()
            ->whereKey($practiceActivityId)
            ->firstOrFail();

        $items = PracticeActivityItem::query()
            ->where(
                'practice_activity_id',
                $activity->id,
            )
            ->with('assessmentItemRevision')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(
                fn (
                    PracticeActivityItem $item
                ): array => $this->itemData(
                    $item
                )
            )
            ->values()
            ->all();

        return ApiResponse::success($items);
    }

    public function storeItem(
        StorePracticeActivityItemRequest $request,
        string $practiceActivityId,
    ): JsonResponse {
        $activity = PracticeActivity::query()
            ->whereKey($practiceActivityId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $activity->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Practice activity membership may only be changed in draft curriculum versions.',
                409,
            );
        }

        $revision = AssessmentItemRevision::query()
            ->whereKey(
                $request->validated(
                    'assessment_item_revision_id'
                )
            )
            ->firstOrFail();

        $result = $this->transactions->run(
            function () use (
                $activity,
                $revision,
                $request,
            ) {
                $lockedActivity =
                    PracticeActivity::query()
                        ->whereKey($activity->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $lockedActivity->status === 'active'
                    && $revision->released_at === null
                ) {
                    return 'unreleased';
                }

                return $this->addItem->execute(
                    $lockedActivity->id,
                    $revision->id,
                    $revision->assessment_item_id,
                    $request->validated(
                        'display_order'
                    ),
                );
            }
        );

        if ($result === 'unreleased') {
            return ApiResponse::error(
                'practice_activity_requires_released_revision',
                'Active practice activities may only contain released assessment item revisions.',
                409,
            );
        }

        $result->load('assessmentItemRevision');

        return ApiResponse::success(
            $this->itemData($result),
            201,
        );
    }

    public function destroyItem(
        string $practiceActivityId,
        string $practiceActivityItemId,
    ): JsonResponse {
        $activity = PracticeActivity::query()
            ->whereKey($practiceActivityId)
            ->firstOrFail();

        $version = CurriculumVersion::query()
            ->whereKey(
                $activity->curriculum_version_id
            )
            ->firstOrFail();

        if ($version->status !== 'draft') {
            return ApiResponse::error(
                'curriculum_version_not_draft',
                'Practice activity membership may only be changed in draft curriculum versions.',
                409,
            );
        }

        $result = $this->transactions->run(
            function () use (
                $activity,
                $practiceActivityItemId,
            ): string {
                $lockedActivity =
                    PracticeActivity::query()
                        ->whereKey($activity->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                $item = PracticeActivityItem::query()
                    ->whereKey(
                        $practiceActivityItemId
                    )
                    ->where(
                        'practice_activity_id',
                        $lockedActivity->id,
                    )
                    ->firstOrFail();

                if (
                    $lockedActivity->status === 'active'
                    && $lockedActivity
                        ->items()
                        ->count() <= 1
                ) {
                    return 'last_item';
                }

                $this->removeItem->execute(
                    $lockedActivity->id,
                    $item->id,
                );

                return 'deleted';
            }
        );

        if ($result === 'last_item') {
            return ApiResponse::error(
                'practice_activity_requires_item',
                'The last item cannot be removed from an active practice activity.',
                409,
            );
        }

        return ApiResponse::success([
            'id' => $practiceActivityItemId,
            'deleted' => true,
        ]);
    }

    private function itemData(
        PracticeActivityItem $item,
    ): array {
        $revision =
            $item->relationLoaded(
                'assessmentItemRevision'
            )
                ? $item->assessmentItemRevision
                : null;

        return [
            'id' => $item->id,
            'practice_activity_id' =>
                $item->practice_activity_id,
            'assessment_item_revision_id' =>
                $item->assessment_item_revision_id,
            'assessment_item_id' =>
                $item->assessment_item_id,
            'curriculum_version_id' =>
                $item->curriculum_version_id,
            'display_order' =>
                $item->display_order,
            'revision' =>
                $revision !== null
                    ? [
                        'id' => $revision->id,
                        'assessment_item_id' =>
                            $revision
                                ->assessment_item_id,
                        'revision_number' =>
                            $revision
                                ->revision_number,
                        'difficulty' =>
                            $revision->difficulty,
                        'released_at' =>
                            $revision
                                ->released_at
                                ?->toISOString(),
                    ]
                    : null,
            'created_at' =>
                $item->created_at?->toISOString(),
        ];
    }

    private function activityData(
        PracticeActivity $activity,
    ): array {
        return [
            'id' => $activity->id,
            'curriculum_version_id' =>
                $activity->curriculum_version_id,
            'lesson_id' => $activity->lesson_id,
            'name' => $activity->name,
            'description' =>
                $activity->description,
            'status' => $activity->status,
            'items_count' =>
                $activity->items_count ?? null,
            'created_at' =>
                $activity->created_at?->toISOString(),
            'updated_at' =>
                $activity->updated_at?->toISOString(),
        ];
    }
}
