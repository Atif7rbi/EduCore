<?php

namespace App\Application\Learning;

use App\Application\Support\TransactionManager;
use App\Models\Lesson;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class UnpublishLesson
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {}

    public function execute(string $lessonId, ?Closure $lockAuthority = null): Lesson
    {
        return $this->transactions->run(
            function () use ($lessonId, $lockAuthority): Lesson {
                $authorizedVersionId = $lockAuthority?->__invoke();
                $lesson = Lesson::query()
                    ->whereKey($lessonId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($authorizedVersionId !== null && $lesson->curriculum_version_id !== $authorizedVersionId) {
                    throw (new ModelNotFoundException)->setModel(Lesson::class, [$lessonId]);
                }

                $lesson->status = 'unpublished';
                $lesson->save();

                return $lesson->refresh();
            }
        );
    }
}
