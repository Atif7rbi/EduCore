<?php

namespace App\Application\Attempt;

use App\Application\Support\TransactionManager;
use App\Models\Attempt;
use App\Models\AttemptItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinalizeAttempt
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(
        string $attemptId,
        string $finalStatus = 'submitted',
    ): Attempt {
        return $this->transactions->run(
            function () use (
                $attemptId,
                $finalStatus,
            ): Attempt {
                $attempt = Attempt::query()
                    ->whereKey($attemptId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $items = AttemptItem::query()
                    ->where('attempt_id', $attempt->id)
                    ->with('response')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($items as $item) {
                    $response = $item->response;

                    if ($response === null) {
                        throw new RuntimeException(
                            'Attempt item is missing its response row.'
                        );
                    }

                    $correctness = $this->score(
                        $item->scoring_snapshot,
                        $response->response_payload,
                    );

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

    /**
     * @param array<string, mixed> $scoringSnapshot
     * @param array<string, mixed>|null $responsePayload
     */
    private function score(
        array $scoringSnapshot,
        ?array $responsePayload,
    ): ?bool {
        if ($responsePayload === null) {
            return null;
        }

        if (
            ! array_key_exists(
                'correct_option',
                $scoringSnapshot
            )
        ) {
            throw new RuntimeException(
                'Unsupported scoring snapshot.'
            );
        }

        if (
            ! array_key_exists(
                'selected_option',
                $responsePayload
            )
        ) {
            return null;
        }

        return $responsePayload['selected_option']
            === $scoringSnapshot['correct_option'];
    }
}
