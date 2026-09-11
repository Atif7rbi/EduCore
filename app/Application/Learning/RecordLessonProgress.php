<?php

namespace App\Application\Learning;

use App\Application\Support\TransactionManager;
use App\Models\LessonProgress;
use App\Models\LessonRevision;
use Illuminate\Support\Facades\DB;

class RecordLessonProgress
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(
        string $learnerProfileId,
        string $lessonRevisionId,
        bool $complete = false,
    ): LessonProgress {
        return $this->transactions->run(
            function () use (
                $learnerProfileId,
                $lessonRevisionId,
                $complete,
            ): LessonProgress {
                LessonRevision::query()
                    ->lockForUpdate()
                    ->findOrFail($lessonRevisionId);

                $progress = LessonProgress::query()
                    ->where('learner_profile_id', $learnerProfileId)
                    ->where('lesson_revision_id', $lessonRevisionId)
                    ->lockForUpdate()
                    ->first();

                if ($progress === null) {
                    $progress = LessonProgress::query()->create([
                        'learner_profile_id' => $learnerProfileId,
                        'lesson_revision_id' => $lessonRevisionId,
                        'status' => 'in_progress',
                        'started_at' => now(),
                        'completed_at' => null,
                    ]);
                }

                if (
                    $complete
                    && $progress->status === 'in_progress'
                ) {
                    DB::table('lesson_progresses')
                        ->where('id', $progress->id)
                        ->update([
                            'status' => 'completed',
                            'completed_at' => now(),
                            'updated_at' => now(),
                        ]);

                    $progress->refresh();
                }

                return $progress;
            }
        );
    }
}
