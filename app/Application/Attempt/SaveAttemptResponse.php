<?php

namespace App\Application\Attempt;

use App\Application\Support\TransactionManager;
use App\Models\AttemptResponse;
use Carbon\CarbonImmutable;

class SaveAttemptResponse
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * @param array<string, mixed>|null $responsePayload
     */
    public function execute(
        string $attemptItemId,
        ?array $responsePayload,
        int $timeSpentMs,
    ): AttemptResponse {
        return $this->transactions->run(
            function () use (
                $attemptItemId,
                $responsePayload,
                $timeSpentMs,
            ): AttemptResponse {
                $response = AttemptResponse::query()
                    ->where('attempt_item_id', $attemptItemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $answerChangeCount = $response->answer_change_count;

                if (
                    $response->response_payload !== null
                    && $response->response_payload != $responsePayload
                ) {
                    $answerChangeCount++;
                }

                $response->response_payload = $responsePayload;
                $response->answer_change_count = $answerChangeCount;
                $response->time_spent_ms = $timeSpentMs;
                $response->updated_at = CarbonImmutable::now('UTC');

                $response->save();

                return $response->refresh();
            }
        );
    }
}
