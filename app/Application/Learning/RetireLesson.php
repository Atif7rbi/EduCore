<?php

namespace App\Application\Learning;

use App\Application\Support\TransactionManager;
use App\Models\Lesson;

class RetireLesson
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {
    }

    public function execute(string $lessonId): Lesson
    {
        return $this->transactions->run(
            function () use ($lessonId): Lesson {
                $lesson = Lesson::query()
                    ->whereKey($lessonId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lesson->status = 'retired';
                $lesson->save();

                return $lesson->refresh();
            }
        );
    }
}
