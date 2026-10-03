<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminStudentReadController extends Controller
{
    public function index(): JsonResponse
    {
        $students = $this->query()
            ->orderBy('users.name')
            ->orderBy('users.email')
            ->orderBy('users.id')
            ->get()
            ->map(
                fn (object $student): array => $this->serialize($student)
            )
            ->values()
            ->all();

        return ApiResponse::success($students);
    }

    public function show(
        string $studentUserId,
    ): JsonResponse {
        $student = $this->query()
            ->where(
                'users.id',
                $studentUserId,
            )
            ->first();

        if ($student === null) {
            abort(404);
        }

        return ApiResponse::success(
            $this->serialize($student)
        );
    }

    private function query(): Builder
    {
        $enrollmentCounts =
            DB::table('student_enrollments')
                ->select(
                    'learner_profile_id'
                )
                ->selectRaw(
                    <<<'SQL'
COUNT(*) FILTER (
    WHERE status = 'pending'
) AS pending_count,
COUNT(*) FILTER (
    WHERE status = 'active'
) AS active_count,
COUNT(*) FILTER (
    WHERE status = 'inactive'
) AS inactive_count,
COUNT(*) AS total_count
SQL
                )
                ->groupBy(
                    'learner_profile_id'
                );

        return DB::table('users')
            ->leftJoin(
                'learner_profiles',
                'learner_profiles.user_id',
                '=',
                'users.id',
            )
            ->leftJoinSub(
                $enrollmentCounts,
                'enrollment_counts',
                function (
                    JoinClause $join,
                ): void {
                    $join->on(
                        'enrollment_counts.learner_profile_id',
                        '=',
                        'learner_profiles.id',
                    );
                },
            )
            ->where(
                'users.role',
                'student',
            )
            ->select([
                'users.id as user_id',
                'learner_profiles.id as learner_profile_id',
                'users.name',
                'users.email',
                'users.role',
                'users.status',
                'users.created_at',
                'learner_profiles.created_at as learner_profile_created_at',
            ])
            ->selectRaw(
                <<<'SQL'
COALESCE(
    enrollment_counts.pending_count,
    0
) AS pending_enrollment_count,
COALESCE(
    enrollment_counts.active_count,
    0
) AS active_enrollment_count,
COALESCE(
    enrollment_counts.inactive_count,
    0
) AS inactive_enrollment_count,
COALESCE(
    enrollment_counts.total_count,
    0
) AS total_enrollment_count
SQL
            );
    }

    private function serialize(
        object $student,
    ): array {
        return [
            /*
             * CDA-009 canonical Student resource
             * identity is users.id.
             *
             * learner_profile_id is educational
             * identity and is never interchangeable
             * with user_id.
             */
            'user_id' => $student->user_id,
            'learner_profile_id' => $student
                ->learner_profile_id,
            'name' => $student->name,
            'email' => $student->email,
            'role' => $student->role,
            'status' => $student->status,
            'enrollment_counts' => [
                'pending' => (int)
                    $student
                        ->pending_enrollment_count,
                'active' => (int)
                    $student
                        ->active_enrollment_count,
                'inactive' => (int)
                    $student
                        ->inactive_enrollment_count,
                'total' => (int)
                    $student
                        ->total_enrollment_count,
            ],
            'created_at' => $this->iso(
                $student->created_at
            ),
            'learner_profile_created_at' => $this->iso(
                $student
                    ->learner_profile_created_at
            ),
        ];
    }

    private function iso(
        mixed $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse(
            $value
        )->toISOString();
    }
}
