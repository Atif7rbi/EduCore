<?php

namespace App\Application\Learning;

use App\Application\Support\TransactionManager;
use App\Models\LessonRevision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReleaseLessonRevision
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(string $lessonRevisionId): LessonRevision
    {
        return $this->transactions->run(
            function () use ($lessonRevisionId): LessonRevision {
                $revision = LessonRevision::query()
                    ->whereKey($lessonRevisionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                DB::table('lesson_revisions')
                    ->where('id', $revision->id)
                    ->update([
                        'released_at' => CarbonImmutable::now('UTC'),
                    ]);

                return $revision->refresh();
            }
        );
    }
}
