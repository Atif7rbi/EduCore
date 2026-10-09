<?php

namespace App\Application\Assessment;

use App\Application\Support\TransactionManager;
use App\Models\AssessmentItem;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class RetireAssessmentItem
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {}

    public function execute(string $assessmentItemId, ?Closure $lockAuthority = null): AssessmentItem
    {
        return $this->transactions->run(
            function () use ($assessmentItemId, $lockAuthority): AssessmentItem {
                $authorizedVersionId = $lockAuthority?->__invoke();
                $item = AssessmentItem::query()
                    ->whereKey($assessmentItemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($authorizedVersionId !== null && $item->curriculum_version_id !== $authorizedVersionId) {
                    throw (new ModelNotFoundException)->setModel(AssessmentItem::class, [$assessmentItemId]);
                }

                $item->status = 'retired';
                $item->save();

                return $item->refresh();
            }
        );
    }
}
