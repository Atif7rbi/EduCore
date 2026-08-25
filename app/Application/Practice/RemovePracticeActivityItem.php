<?php

namespace App\Application\Practice;

use App\Application\Support\TransactionManager;
use App\Models\PracticeActivity;
use App\Models\PracticeActivityItem;

class RemovePracticeActivityItem
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(
        string $practiceActivityId,
        string $practiceActivityItemId,
    ): void {
        $this->transactions->run(
            function () use (
                $practiceActivityId,
                $practiceActivityItemId,
            ): void {
                $activity = PracticeActivity::query()
                    ->whereKey($practiceActivityId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $item = PracticeActivityItem::query()
                    ->whereKey($practiceActivityItemId)
                    ->where(
                        'practice_activity_id',
                        $activity->id
                    )
                    ->firstOrFail();

                $item->delete();
            }
        );
    }
}
