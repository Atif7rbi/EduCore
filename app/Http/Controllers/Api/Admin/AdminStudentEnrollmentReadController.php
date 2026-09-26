<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\LearnerProfile;
use App\Models\StudentEnrollment;
use App\Models\StudentEnrollmentTransition;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminStudentEnrollmentReadController extends Controller
{
    public function index(
        string $studentUserId,
    ): JsonResponse {
        $student = User::query()
            ->whereKey($studentUserId)
            ->where('role', 'student')
            ->firstOrFail();

        /*
         * CDA-009 route identity is users.id, but
         * StudentEnrollment scope is always resolved
         * through the exact LearnerProfile UUID.
         */
        $learnerProfile =
            LearnerProfile::query()
                ->where(
                    'user_id',
                    $student->id,
                )
                ->firstOrFail();

        $enrollments =
            StudentEnrollment::query()
                ->where(
                    'learner_profile_id',
                    $learnerProfile->id,
                )
                ->with([
                    'learnerProfile.user',
                    'teacherSubjectAssignment.teacher',
                    'teacherSubjectAssignment.subject',
                ])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->map(
                    fn (
                        StudentEnrollment $enrollment
                    ): array => $this->enrollmentData(
                        $enrollment,
                        false,
                    )
                )
                ->values()
                ->all();

        return ApiResponse::success(
            $enrollments
        );
    }

    public function show(
        string $enrollmentId,
    ): JsonResponse {
        $enrollment =
            StudentEnrollment::query()
                ->whereKey($enrollmentId)
                ->with([
                    'learnerProfile.user',
                    'teacherSubjectAssignment.teacher',
                    'teacherSubjectAssignment.subject',
                    'transitions' => fn ($query) => $query->orderBy(
                        'sequence_number'
                    ),
                    'transitions.actor',
                ])
                ->firstOrFail();

        return ApiResponse::success(
            $this->enrollmentData(
                $enrollment,
                true,
            )
        );
    }

    private function enrollmentData(
        StudentEnrollment $enrollment,
        bool $includeHistory,
    ): array {
        $learnerProfile =
            $enrollment->learnerProfile;

        $student =
            $learnerProfile->user;

        $assignment =
            $enrollment
                ->teacherSubjectAssignment;

        $teacher = $assignment->teacher;
        $subject = $assignment->subject;

        $data = [
            'id' => $enrollment->id,

            /*
             * Keep actor identity and educational
             * identity explicit and non-interchangeable.
             */
            'student' => [
                'user_id' => $student->id,
                'learner_profile_id' => $learnerProfile->id,
                'name' => $student->name,
                'email' => $student->email,
                'status' => $student->status,
            ],

            /*
             * These are raw lifecycle states.
             * They are not an effective-access claim.
             */
            'status' => $enrollment->status,

            'teacher_subject_assignment' => [
                'id' => $assignment->id,
                'status' => $assignment->status,
                'teacher' => [
                    'user_id' => $teacher->id,
                    'name' => $teacher->name,
                    'email' => $teacher->email,
                    'status' => $teacher->status,
                ],
                'subject' => [
                    'id' => $subject->id,
                    'code' => $subject->code,
                    'name' => $subject->name,
                    'status' => $subject->status,
                ],
            ],

            'created_at' => $enrollment->created_at
                ?->toISOString(),
            'updated_at' => $enrollment->updated_at
                ?->toISOString(),
        ];

        if ($includeHistory) {
            $data['transitions'] =
                $enrollment->transitions
                    ->map(
                        fn (
                            StudentEnrollmentTransition $transition
                        ): array => [
                            'sequence_number' => (int) $transition
                                ->sequence_number,
                            'from_status' => $transition
                                ->from_status,
                            'to_status' => $transition
                                ->to_status,
                            'outcome' => $transition->outcome,
                            'actor_user_id' => $transition
                                ->actor_user_id,
                            /*
                             * actor_user_id is the immutable event
                             * provenance. These display fields reflect
                             * the User's current state, not an event-time
                             * snapshot.
                             */
                            'actor_current' => [
                                'name' => $transition
                                    ->actor
                                    ->name,
                                'role' => $transition
                                    ->actor
                                    ->role,
                                'status' => $transition
                                    ->actor
                                    ->status,
                            ],
                            'operation_id' => $transition
                                ->operation_id,
                            'reason' => $transition->reason,
                            'effective_at' => $transition
                                ->effective_at
                                ?->toISOString(),
                            'created_at' => $transition
                                ->created_at
                                ?->toISOString(),
                        ]
                    )
                    ->values()
                    ->all();
        }

        return $data;
    }
}
