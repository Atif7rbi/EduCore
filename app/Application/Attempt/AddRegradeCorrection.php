<?php

namespace App\Application\Attempt;

use App\Application\Support\TransactionManager;
use App\Models\AttemptResponse;
use App\Models\RegradeCorrection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AddRegradeCorrection
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(
        string $attemptResponseId,
        bool $correctedIsCorrect,
        string $reason,
    ): RegradeCorrection {
        return $this->transactions->run(
            function () use (
                $attemptResponseId,
                $correctedIsCorrect,
                $reason,
            ): RegradeCorrection {
                $response = AttemptResponse::query()
                    ->whereKey($attemptResponseId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $nextCorrectionNumber = (int) DB::table('regrade_corrections')
                    ->where('attempt_response_id', $response->id)
                    ->max('correction_number') + 1;

                return RegradeCorrection::query()->create([
                    'attempt_response_id' => $response->id,
                    'correction_number' => $nextCorrectionNumber,
                    'corrected_is_correct' => $correctedIsCorrect,
                    'reason' => $reason,
                    'corrected_at' => CarbonImmutable::now('UTC'),
                ]);
            }
        );
    }
}
