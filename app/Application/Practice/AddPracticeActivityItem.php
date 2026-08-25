<?php

namespace App\Application\Practice;

use App\Application\Support\TransactionManager;
use App\Models\PracticeActivity;
use App\Models\PracticeActivityItem;
use Illuminate\Support\Str;

class AddPracticeActivityItem
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(
        string $practiceActivityId,
        string $assessmentItemRevisionId,
        string $assessmentItemId,
        int $displayOrder,
    ): PracticeActivityItem {
        return $this->transactions->run(
            function () use (
                $practiceActivityId,
                $assessmentItemRevisionId,
                $assessmentItemId,
                $displayOrder,
            ): PracticeActivityItem {
                $activity = PracticeActivity::query()
                    ->whereKey($practiceActivityId)
                    ->lockForUpdate()
                    ->firstOrFail();

                return PracticeActivityItem::query()->create([
                    'id' => (string) Str::uuid(),
                    'practice_activity_id' => $activity->id,
                    'assessment_item_revision_id' => $assessmentItemRevisionId,
                    'assessment_item_id' => $assessmentItemId,
                    'curriculum_version_id' => $activity->curriculum_version_id,
                    'display_order' => $displayOrder,
                ]);
            }
        );
    }
}
