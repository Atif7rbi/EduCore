<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Application\TeacherMonitoring\TeacherMonitoringScope;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Attempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TeacherAttemptMonitoringController extends Controller
{
    public function index(
        string $enrollmentId,
        Request $request,
        TeacherMonitoringScope $monitoring,
    ): JsonResponse {
        $validated = $request->validate([
            'curriculum_version_id' => ['required', 'uuid'],
            'kind' => ['nullable', Rule::in(['practice', 'exam'])],
            'status' => [
                'nullable',
                Rule::in(['submitted', 'abandoned']),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        /** @var User $teacher */
        $teacher = $request->user();
        $scope = $monitoring->resolveEnrollment(
            authenticatedTeacherUserId: $teacher->id,
            studentEnrollmentId: $enrollmentId,
            curriculumVersionId: $validated['curriculum_version_id'],
        );
        $summary = $monitoring->authorizedSummary($teacher->id, $scope);
        $query = $monitoring->finalizedAttempts(
            Attempt::query()->with($this->resultRelations()),
            $teacher->id,
            $scope,
        );

        if (($validated['kind'] ?? null) === 'practice') {
            $query->whereNotNull('attempts.practice_activity_id');
        } elseif (($validated['kind'] ?? null) === 'exam') {
            $query->whereNotNull('attempts.exam_generation_id');
        }

        if (isset($validated['status'])) {
            $query->where('attempts.status', $validated['status']);
        }

        $attempts = $query
            ->orderByDesc('attempts.finalized_at')
            ->orderByDesc('attempts.id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::success([
            ...$this->scopePayload($summary),
            'items' => collect($attempts->items())
                ->map(fn (Attempt $attempt): array => $this->attemptData($attempt))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $attempts->currentPage(),
                'per_page' => $attempts->perPage(),
                'total' => $attempts->total(),
                'last_page' => $attempts->lastPage(),
            ],
        ]);
    }

    public function result(
        string $enrollmentId,
        string $attemptId,
        Request $request,
        TeacherMonitoringScope $monitoring,
    ): JsonResponse {
        /** @var User $teacher */
        $teacher = $request->user();
        $scope = $monitoring->resolveFinalizedAttempt(
            authenticatedTeacherUserId: $teacher->id,
            studentEnrollmentId: $enrollmentId,
            attemptId: $attemptId,
        );
        $attempt = $monitoring
            ->finalizedAttempts(
                Attempt::query()
                    ->whereKey($attemptId)
                    ->with($this->resultRelations()),
                $teacher->id,
                $scope,
            )
            ->firstOrFail();
        $summary = $monitoring->authorizedSummary($teacher->id, $scope);

        return ApiResponse::success([
            ...$this->scopePayload($summary),
            'attempt' => $this->attemptData($attempt),
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    private function resultRelations(): array
    {
        return [
            'items' => fn ($query) => $query
                ->orderBy('presentation_position')
                ->orderBy('id'),
            'items.response',
            'items.response.regradeCorrections' => fn ($query) => $query
                ->orderByDesc('correction_number')
                ->orderByDesc('id'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptData(Attempt $attempt): array
    {
        $kind = $attempt->practice_activity_id !== null
            ? 'practice'
            : 'exam';

        return [
            'id' => $attempt->id,
            'kind' => $kind,
            'source' => [
                'practice_activity_id' => $attempt->practice_activity_id,
                'exam_generation_id' => $attempt->exam_generation_id,
            ],
            'curriculum_version_id' => $attempt->curriculum_version_id,
            'status' => $attempt->status,
            'started_at' => $attempt->started_at?->toISOString(),
            'finalized_at' => $attempt->finalized_at?->toISOString(),
            'result' => $attempt->status === 'submitted'
                ? [
                    'availability' => 'available',
                    'summary' => $this->effectiveSummary($attempt),
                ]
                : [
                    'availability' => 'not_applicable',
                    'reason' => 'attempt_abandoned',
                    'summary' => null,
                ],
        ];
    }

    /**
     * @return array{answered: int, correct: int, incorrect: int, unanswered: int, total: int}
     */
    private function effectiveSummary(Attempt $attempt): array
    {
        $answered = 0;
        $correct = 0;
        $incorrect = 0;
        $unanswered = 0;

        foreach ($attempt->items as $item) {
            $response = $item->response;
            $effective = $response === null
                ? null
                : ($response->regradeCorrections->first()?->corrected_is_correct
                    ?? $response->original_is_correct);

            if ($effective === null) {
                $unanswered++;

                continue;
            }

            $answered++;
            $effective ? $correct++ : $incorrect++;
        }

        return [
            'answered' => $answered,
            'correct' => $correct,
            'incorrect' => $incorrect,
            'unanswered' => $unanswered,
            'total' => $attempt->items->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scopePayload(object $summary): array
    {
        return [
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
        ];
    }
}
