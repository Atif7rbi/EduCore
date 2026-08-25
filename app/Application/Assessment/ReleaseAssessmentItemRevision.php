<?php

namespace App\Application\Assessment;

use App\Application\Support\TransactionManager;
use App\Models\AssessmentItemRevision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReleaseAssessmentItemRevision
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(string $assessmentItemRevisionId): AssessmentItemRevision
    {
        return $this->transactions->run(
            function () use ($assessmentItemRevisionId): AssessmentItemRevision {
                $revision = AssessmentItemRevision::query()
                    ->whereKey($assessmentItemRevisionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                DB::table('assessment_item_revisions')
                    ->where('id', $revision->id)
                    ->update([
                        'released_at' => CarbonImmutable::now('UTC'),
                    ]);

                return $revision->refresh();
            }
        );
    }
}
