<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminTeacherReadController extends Controller
{
    public function index(): JsonResponse
    {
        $teachers = $this->query()
            ->orderBy('users.name')
            ->orderBy('users.email')
            ->orderBy('users.id')
            ->get()
            ->map(
                fn (object $teacher): array => $this->serialize($teacher)
            )
            ->values()
            ->all();

        return ApiResponse::success($teachers);
    }

    public function show(
        string $teacherUserId,
    ): JsonResponse {
        $teacher = $this->query()
            ->where(
                'users.id',
                $teacherUserId,
            )
            ->first();

        if ($teacher === null) {
            abort(404);
        }

        return ApiResponse::success(
            $this->serialize($teacher)
        );
    }

    private function query(): Builder
    {
        $assignmentCounts =
            DB::table(
                'teacher_subject_assignments'
            )
                ->select('teacher_id')
                ->selectRaw(
                    <<<'SQL'
COUNT(*) FILTER (
    WHERE status = 'active'
) AS active_count,
COUNT(*) FILTER (
    WHERE status = 'inactive'
) AS inactive_count,
COUNT(*) AS total_count
SQL
                )
                ->groupBy('teacher_id');

        return DB::table('users')
            ->leftJoin(
                'teacher_account_provisionings as provisioning',
                'provisioning.teacher_user_id',
                '=',
                'users.id',
            )
            ->leftJoinSub(
                $assignmentCounts,
                'assignment_counts',
                function (
                    JoinClause $join,
                ): void {
                    $join->on(
                        'assignment_counts.teacher_id',
                        '=',
                        'users.id',
                    );
                },
            )
            ->where(
                'users.role',
                'teacher',
            )
            ->select([
                'users.id as user_id',
                'users.name',
                'users.email',
                'users.role',
                'users.status',
                'users.created_at',
                'provisioning.provisioned_by_user_id',
                'provisioning.setup_completed_at',
                'provisioning.created_at as provisioning_created_at',
            ])
            ->selectRaw(
                <<<'SQL'
COALESCE(
    assignment_counts.active_count,
    0
) AS active_assignment_count,
COALESCE(
    assignment_counts.inactive_count,
    0
) AS inactive_assignment_count,
COALESCE(
    assignment_counts.total_count,
    0
) AS total_assignment_count
SQL
            );
    }

    private function serialize(
        object $teacher,
    ): array {
        $tracked =
            $teacher->provisioned_by_user_id
            !== null;

        $provisioningState =
            ! $tracked
                ? 'untracked'
                : (
                    $teacher->setup_completed_at
                    === null
                        ? 'pending_setup'
                        : 'completed'
                );

        return [
            'user_id' => $teacher->user_id,
            'name' => $teacher->name,
            'email' => $teacher->email,
            'role' => $teacher->role,
            'status' => $teacher->status,
            'provisioning' => [
                'state' => $provisioningState,
                'provisioned_by_user_id' => $teacher
                    ->provisioned_by_user_id,
                'setup_completed_at' => $this->iso(
                    $teacher
                        ->setup_completed_at
                ),
                'created_at' => $this->iso(
                    $teacher
                        ->provisioning_created_at
                ),
            ],
            'assignment_counts' => [
                'active' => (int)
                $teacher
                    ->active_assignment_count,
                'inactive' => (int)
                $teacher
                    ->inactive_assignment_count,
                'total' => (int)
                $teacher
                    ->total_assignment_count,
            ],
            'created_at' => $this->iso(
                $teacher->created_at
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
