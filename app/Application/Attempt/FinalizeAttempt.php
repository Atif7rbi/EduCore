<?php

namespace App\Application\Attempt;

use App\Application\Support\TransactionManager;
use App\Models\Attempt;
use App\Models\AttemptResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FinalizeAttempt
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * @param array<string, bool|null> $correctnessByAttemptItemId
     */
    public function execute(
        string $attemptId,
        array $correctnessByAttemptItemId,
        string $finalStatus = 'submitted',
    ): Attempt {
        return $this->transactions->run(
            function () use (
                $attemptId,
                $correctnessByAttemptItemId,
                $finalStatus,
            ): Attempt {
                $attempt = Attempt::query()
                    ->whereKey($attemptId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $responses = AttemptResponse::query()
                    ->join(
                        'attempt_items',
                        'attempt_items.id',
                        '=',
                        'attempt_responses.attempt_item_id'
                    )
                    ->where('attempt_items.attempt_id', $attempt->id)
                    ->select('attempt_responses.*')
                    ->orderBy('attempt_responses.id')
                    ->lockForUpdate()
                    ->get();

                foreach ($responses as $response) {
                    $correctness = $correctnessByAttemptItemId[
                        $response->attempt_item_id
                    ] ?? null;

                    DB::table('attempt_responses')
                        ->where('id', $response->id)
                        ->update([
                            'original_is_correct' => $correctness,
                            'updated_at' => CarbonImmutable::now('UTC'),
                        ]);
                }

                DB::table('attempts')
                    ->where('id', $attempt->id)
                    ->update([
                        'status' => $finalStatus,
                        'finalized_at' => CarbonImmutable::now('UTC'),
                        'updated_at' => CarbonImmutable::now('UTC'),
                    ]);

                return $attempt->refresh();
            }
        );
    }
}
