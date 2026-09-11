<?php

namespace App\Application\Learning;

use App\Application\Support\TransactionManager;
use App\Models\Lesson;

class PublishLesson
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(
        string $lessonId,
        string $publishedRevisionId,
    ): Lesson {
        return $this->transactions->run(
            function () use ($lessonId, $publishedRevisionId): Lesson {
                $lesson = Lesson::query()
                    ->whereKey($lessonId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lesson->published_revision_id = $publishedRevisionId;
                $lesson->status = 'published';
                $lesson->save();

                return $lesson->refresh();
            }
        );
    }
}
