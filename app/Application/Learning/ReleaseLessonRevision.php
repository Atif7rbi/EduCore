<?php

namespace App\Application\Learning;

use App\Application\Support\TransactionManager;
use App\Models\LessonRevision;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ReleaseLessonRevision
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {}

    public function execute(string $lessonRevisionId, ?Closure $lockAuthority = null): LessonRevision
    {
        return $this->transactions->run(
            function () use ($lessonRevisionId, $lockAuthority): LessonRevision {
                $authorizedVersionId = $lockAuthority?->__invoke();
                $revision = LessonRevision::query()
                    ->whereKey($lessonRevisionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($authorizedVersionId !== null && $revision->curriculum_version_id !== $authorizedVersionId) {
                    throw (new ModelNotFoundException)->setModel(LessonRevision::class, [$lessonRevisionId]);
                }

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
