<?php

namespace App\Application\Attempt;

use App\Application\Authorization\LockActiveLearnerCurriculumGrant;
use App\Application\Support\TransactionManager;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\AttemptResponse;
use Carbon\CarbonImmutable;

class SaveAttemptResponse
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly LockActiveLearnerCurriculumGrant $learnerGrant,
    ) {}

    /**
     * @param  array<string, mixed>|null  $responsePayload
     */
    public function execute(
        string $learnerProfileId,
        string $attemptItemId,
        ?array $responsePayload,
        int $timeSpentMs,
    ): AttemptResponse {
        return $this->transactions->run(
            function () use (
                $learnerProfileId,
                $attemptItemId,
                $responsePayload,
                $timeSpentMs,
            ): AttemptResponse {
                /*
                 * Identity discovery is intentionally unlocked.
                 * Ownership and authorization are revalidated
                 * authoritatively under locks below.
                 */
                $itemIdentity = AttemptItem::query()
                    ->whereKey($attemptItemId)
                    ->firstOrFail([
                        'id',
                        'attempt_id',
                        'curriculum_version_id',
                    ]);

                $attemptIdentity = Attempt::query()
                    ->whereKey($itemIdentity->attempt_id)
                    ->firstOrFail([
                        'id',
                        'curriculum_version_id',
                    ]);

                $this->learnerGrant->execute(
                    $learnerProfileId,
                    $attemptIdentity->curriculum_version_id,
                );

                $attempt = Attempt::query()
                    ->whereKey($attemptIdentity->id)
                    ->where(
                        'learner_profile_id',
                        $learnerProfileId,
                    )
                    ->where(
                        'curriculum_version_id',
                        $attemptIdentity->curriculum_version_id,
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                AttemptItem::query()
                    ->whereKey($attemptItemId)
                    ->where(
                        'attempt_id',
                        $attempt->id,
                    )
                    ->where(
                        'curriculum_version_id',
                        $attempt->curriculum_version_id,
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $response = AttemptResponse::query()
                    ->where(
                        'attempt_item_id',
                        $attemptItemId,
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $answerChangeCount =
                    $response->answer_change_count;

                if (
                    $response->response_payload !== null
                    && $response->response_payload
                        != $responsePayload
                ) {
                    $answerChangeCount++;
                }

                $response->response_payload =
                    $responsePayload;

                $response->answer_change_count =
                    $answerChangeCount;

                $response->time_spent_ms =
                    $timeSpentMs;

                $response->updated_at =
                    CarbonImmutable::now('UTC');

                $response->save();

                return $response->refresh();
            }
        );
    }
}
