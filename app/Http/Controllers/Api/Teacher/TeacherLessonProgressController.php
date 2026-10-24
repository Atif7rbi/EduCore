<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Application\TeacherMonitoring\TeacherMonitoringScope;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TeacherLessonProgressController extends Controller
{
    public function index(
        string $enrollmentId,
        Request $request,
        TeacherMonitoringScope $monitoring,
    ): JsonResponse {
        $validated = $request->validate([
            'curriculum_version_id' => [
                'required',
                'uuid',
            ],
        ]);

        /** @var User $teacher */
        $teacher = $request->user();
        $scope = $monitoring->resolveEnrollment(
            authenticatedTeacherUserId: $teacher->id,
            studentEnrollmentId: $enrollmentId,
            curriculumVersionId: $validated['curriculum_version_id'],
        );
        $summary = $monitoring->authorizedSummary(
            $teacher->id,
            $scope,
        );

        $recorded = $monitoring
            ->lessonProgresses(
                LessonProgress::query()
                    ->join(
                        'lesson_revisions as progress_revisions',
                        'progress_revisions.id',
                        '=',
                        'lesson_progresses.lesson_revision_id',
                    )
                    ->join(
                        'lessons as progress_lessons',
                        'progress_lessons.id',
                        '=',
                        'progress_revisions.lesson_id',
                    )
                    ->select([
                        'lesson_progresses.id as progress_id',
                        'lesson_progresses.status as progress_status',
                        'lesson_progresses.started_at',
                        'lesson_progresses.completed_at',
                        'progress_lessons.id as lesson_id',
                        'progress_lessons.title as lesson_title',
                        'progress_lessons.display_order',
                        'progress_revisions.id as lesson_revision_id',
                        'progress_revisions.revision_number',
                        'progress_revisions.released_at',
                    ]),
                $teacher->id,
                $scope,
            )
            ->orderBy('progress_lessons.display_order')
            ->orderBy('progress_lessons.id')
            ->orderBy('progress_revisions.revision_number')
            ->orderBy('progress_revisions.id')
            ->get();

        $recordedLessonIds = $recorded
            ->pluck('lesson_id')
            ->unique()
            ->all();
        $catalog = $monitoring
            ->releasedLessons(
                Lesson::query()
                    ->join(
                        'lesson_revisions as catalog_revisions',
                        'catalog_revisions.id',
                        '=',
                        'lessons.published_revision_id',
                    )
                    ->select([
                        'lessons.id as lesson_id',
                        'lessons.title as lesson_title',
                        'lessons.display_order',
                        'catalog_revisions.id as lesson_revision_id',
                        'catalog_revisions.revision_number',
                        'catalog_revisions.released_at',
                    ]),
                $teacher->id,
                $scope,
            )
            ->when(
                $recordedLessonIds !== [],
                fn ($query) => $query->whereNotIn(
                    'lessons.id',
                    $recordedLessonIds,
                ),
            )
            ->orderBy('lessons.display_order')
            ->orderBy('lessons.id')
            ->get();

        $items = $recorded
            ->map(fn ($row): array => $this->progressItem($row))
            ->concat(
                $catalog->map(fn ($row): array => $this->catalogItem($row)),
            )
            ->sortBy(fn (array $item): string => sprintf(
                '%010d|%s|%010d|%s',
                $item['lesson']['display_order'],
                $item['lesson']['id'],
                $item['lesson_revision']['revision_number'],
                $item['lesson_revision']['id'],
            ))
            ->values();

        return ApiResponse::success([
            'enrollment' => [
                'id' => $summary->enrollment_id,
                'status' => 'active',
                'student' => [
                    'user_id' => $summary->student_user_id,
                    'name' => $summary->student_name,
                ],
            ],
            'teacher_subject_assignment' => [
                'id' => $summary->assignment_id,
                'status' => 'active',
                'subject' => [
                    'id' => $summary->subject_id,
                    'code' => $summary->subject_code,
                    'name' => $summary->subject_name,
                ],
            ],
            'curriculum' => [
                'id' => $summary->curriculum_id,
                'name' => $summary->curriculum_name,
            ],
            'curriculum_version' => [
                'id' => $summary->curriculum_version_id,
                'version_number' => (int) $summary->version_number,
                'label' => $summary->version_label,
                'status' => $summary->version_status,
            ],
            'items' => $items->all(),
            'recorded_started_count' => $recorded
                ->whereNotNull('started_at')
                ->count(),
            'recorded_completed_count' => $recorded
                ->where('progress_status', 'completed')
                ->count(),
        ]);
    }

    /**
     * @param  object{progress_status: string, started_at: mixed, completed_at: mixed, lesson_id: string, lesson_title: string, display_order: int|string, lesson_revision_id: string, revision_number: int|string, released_at: mixed}  $row
     * @return array<string, mixed>
     */
    private function progressItem(object $row): array
    {
        return $this->item(
            $row,
            $row->progress_status,
            $this->iso($row->started_at),
            $this->iso($row->completed_at),
        );
    }

    /**
     * @param  object{lesson_id: string, lesson_title: string, display_order: int|string, lesson_revision_id: string, revision_number: int|string, released_at: mixed}  $row
     * @return array<string, mixed>
     */
    private function catalogItem(object $row): array
    {
        return $this->item($row, 'not_recorded', null, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(
        object $row,
        string $status,
        ?string $startedAt,
        ?string $completedAt,
    ): array {
        return [
            'lesson' => [
                'id' => $row->lesson_id,
                'title' => $row->lesson_title,
                'display_order' => (int) $row->display_order,
            ],
            'lesson_revision' => [
                'id' => $row->lesson_revision_id,
                'revision_number' => (int) $row->revision_number,
                'released_at' => $this->iso($row->released_at),
            ],
            'progress' => [
                'status' => $status,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
            ],
        ];
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $value)
            ->utc()
            ->toISOString();
    }
}
