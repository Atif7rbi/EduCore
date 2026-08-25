<?php

namespace App\Application\Assessment;

use App\Application\Support\TransactionManager;
use App\Models\AssessmentItem;

class PublishAssessmentItem
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(
        string $assessmentItemId,
        string $publishedRevisionId,
    ): AssessmentItem {
        return $this->transactions->run(
            function () use (
                $assessmentItemId,
                $publishedRevisionId,
            ): AssessmentItem {
                $item = AssessmentItem::query()
                    ->whereKey($assessmentItemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $item->published_revision_id = $publishedRevisionId;
                $item->status = 'published';
                $item->save();

                return $item->refresh();
            }
        );
    }
}
