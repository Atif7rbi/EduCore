<?php

namespace App\Application\Assessment;

use App\Application\Support\TransactionManager;
use App\Models\AssessmentItem;

class RetireAssessmentItem
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(string $assessmentItemId): AssessmentItem
    {
        return $this->transactions->run(
            function () use ($assessmentItemId): AssessmentItem {
                $item = AssessmentItem::query()
                    ->whereKey($assessmentItemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $item->status = 'retired';
                $item->save();

                return $item->refresh();
            }
        );
    }
}
