<?php

namespace App\Application\Assessment;

use App\Application\Support\TransactionManager;
use App\Models\AssessmentItemRevision;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ReleaseAssessmentItemRevision
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {}

    public function execute(string $assessmentItemRevisionId, ?Closure $lockAuthority = null): AssessmentItemRevision
    {
        return $this->transactions->run(
            function () use ($assessmentItemRevisionId, $lockAuthority): AssessmentItemRevision {
                $authorizedVersionId = $lockAuthority?->__invoke();
                $revision = AssessmentItemRevision::query()
                    ->whereKey($assessmentItemRevisionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($authorizedVersionId !== null && $revision->curriculum_version_id !== $authorizedVersionId) {
                    throw (new ModelNotFoundException)->setModel(AssessmentItemRevision::class, [$assessmentItemRevisionId]);
                }

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
