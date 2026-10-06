<?php

namespace App\Application\Learning;

use App\Application\Support\TransactionManager;
use App\Models\Lesson;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PublishLesson
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {}

    public function execute(
        string $lessonId,
        string $publishedRevisionId,
        ?Closure $lockAuthority = null,
    ): Lesson {
        return $this->transactions->run(
            function () use ($lessonId, $publishedRevisionId, $lockAuthority): Lesson {
                $authorizedVersionId = $lockAuthority?->__invoke();
                $lesson = Lesson::query()
                    ->whereKey($lessonId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($authorizedVersionId !== null && $lesson->curriculum_version_id !== $authorizedVersionId) {
                    throw (new ModelNotFoundException)->setModel(Lesson::class, [$lessonId]);
                }

                $lesson->published_revision_id = $publishedRevisionId;
                $lesson->status = 'published';
                $lesson->save();

                return $lesson->refresh();
            }
        );
    }
}
