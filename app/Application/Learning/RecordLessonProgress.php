<?php

namespace App\Application\Learning;

use App\Application\Authorization\LockActiveLearnerCurriculumGrant;
use App\Application\Support\TransactionManager;
use App\Models\CurriculumVersion;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonRevision;
use Illuminate\Support\Facades\DB;

class RecordLessonProgress
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly LockActiveLearnerCurriculumGrant $learnerGrant,
    ) {}

    public function execute(
        string $authenticatedUserId,
        string $learnerProfileId,
        string $lessonId,
        bool $complete = false,
    ): LessonProgress {
        return $this->transactions->run(
            function () use (
                $authenticatedUserId,
                $learnerProfileId,
                $lessonId,
                $complete,
            ): LessonProgress {
                /*
                 * Identity discovery is intentionally unlocked.
                 * All learner authorization and source eligibility
                 * are revalidated authoritatively below.
                 */
                $lessonIdentity = Lesson::query()
                    ->whereKey($lessonId)
                    ->firstOrFail([
                        'id',
                        'curriculum_version_id',
                    ]);

                $this->learnerGrant->execute(
                    $authenticatedUserId,
                    $learnerProfileId,
                    $lessonIdentity->curriculum_version_id,
                );

                /*
                 * Canonical continuation after learner grant:
                 *
                 * CurriculumVersion
                 * -> Lesson
                 * -> LessonRevision
                 * -> LessonProgress
                 */
                $curriculumVersion =
                    CurriculumVersion::query()
                        ->whereKey(
                            $lessonIdentity->curriculum_version_id,
                        )
                        ->where('status', 'published')
                        ->lockForUpdate()
                        ->firstOrFail();

                $lesson = Lesson::query()
                    ->whereKey($lessonId)
                    ->where(
                        'curriculum_version_id',
                        $curriculumVersion->id,
                    )
                    ->where('status', 'published')
                    ->whereNotNull('published_revision_id')
                    ->lockForUpdate()
                    ->firstOrFail();

                $revision = LessonRevision::query()
                    ->whereKey($lesson->published_revision_id)
                    ->where('lesson_id', $lesson->id)
                    ->where(
                        'curriculum_version_id',
                        $curriculumVersion->id,
                    )
                    ->whereNotNull('released_at')
                    ->lockForUpdate()
                    ->firstOrFail();

                $progress = LessonProgress::query()
                    ->where(
                        'learner_profile_id',
                        $learnerProfileId,
                    )
                    ->where(
                        'lesson_revision_id',
                        $revision->id,
                    )
                    ->lockForUpdate()
                    ->first();

                if ($progress === null) {
                    $progress = LessonProgress::query()->create([
                        'learner_profile_id' => $learnerProfileId,
                        'lesson_revision_id' => $revision->id,
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
