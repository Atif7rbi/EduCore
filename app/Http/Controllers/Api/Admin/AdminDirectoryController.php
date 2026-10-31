<?php

namespace App\Http\Controllers\Api\Admin;

use App\Application\AdminDirectory\RunAdminDirectorySnapshot;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminDirectoryController extends Controller
{
    public function teachers(
        Request $request,
        RunAdminDirectorySnapshot $snapshot,
    ): JsonResponse {
        $parameters = $this->parameters($request);

        return ApiResponse::success(
            $snapshot->execute(
                fn (Connection $connection): array => $this->teacherPage(
                    $connection,
                    $parameters,
                )
            )
        );
    }

    public function students(
        Request $request,
        RunAdminDirectorySnapshot $snapshot,
    ): JsonResponse {
        $parameters = $this->parameters($request);

        return ApiResponse::success(
            $snapshot->execute(
                fn (Connection $connection): array => $this->studentPage(
                    $connection,
                    $parameters,
                )
            )
        );
    }

    private function parameters(Request $request): array
    {
        $input = $request->query();

        if (
            array_key_exists('search', $input)
            && is_string($input['search'])
        ) {
            $input['search'] = trim($input['search']);
        }

        $validated = Validator::make(
            $input,
            [
                'page' => [
                    'sometimes',
                    'integer',
                    'min:1',
                ],
                'per_page' => [
                    'sometimes',
                    'integer',
                    'min:1',
                    'max:50',
                ],
                'search' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:120',
                ],
                'status' => [
                    'sometimes',
                    Rule::in([
                        'active',
                        'disabled',
                    ]),
                ],
            ],
        )->validate();

        $search = $validated['search'] ?? null;

        return [
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? 20),
            'search' => $search === '' ? null : $search,
            'status' => $validated['status'] ?? null,
        ];
    }

    private function teacherPage(
        Connection $connection,
        array $parameters,
    ): array {
        $assignmentCounts = $connection
            ->table('teacher_subject_assignments')
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

        $query = $connection
            ->table('users')
            ->leftJoin(
                'teacher_account_provisionings as provisioning',
                'provisioning.teacher_user_id',
                '=',
                'users.id',
            )
            ->leftJoinSub(
                $assignmentCounts,
                'assignment_counts',
                function (JoinClause $join): void {
                    $join->on(
                        'assignment_counts.teacher_id',
                        '=',
                        'users.id',
                    );
                },
            )
            ->where('users.role', 'teacher');

        $this->applyFilters(
            $query,
            $parameters,
            false,
        );

        $total = (clone $query)->count('users.id');

        if ($this->isPageOutOfRange($total, $parameters)) {
            return $this->page(
                [],
                $total,
                $parameters,
            );
        }

        $items = $query
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
            )
            ->orderBy('users.name')
            ->orderBy('users.email')
            ->orderBy('users.id')
            ->forPage(
                $parameters['page'],
                $parameters['per_page'],
            )
            ->get()
            ->map(
                fn (object $teacher): array => $this->serializeTeacher($teacher)
            )
            ->values()
            ->all();

        return $this->page(
            $items,
            $total,
            $parameters,
        );
    }

    private function studentPage(
        Connection $connection,
        array $parameters,
    ): array {
        $enrollmentCounts = $connection
            ->table('student_enrollments')
            ->select('learner_profile_id')
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
            ->groupBy('learner_profile_id');

        $query = $connection
            ->table('users')
            ->leftJoin(
                'learner_profiles',
                'learner_profiles.user_id',
                '=',
                'users.id',
            )
            ->leftJoinSub(
                $enrollmentCounts,
                'enrollment_counts',
                function (JoinClause $join): void {
                    $join->on(
                        'enrollment_counts.learner_profile_id',
                        '=',
                        'learner_profiles.id',
                    );
                },
            )
            ->where('users.role', 'student');

        $this->applyFilters(
            $query,
            $parameters,
            true,
        );

        $total = (clone $query)->count('users.id');

        if ($this->isPageOutOfRange($total, $parameters)) {
            return $this->page(
                [],
                $total,
                $parameters,
            );
        }

        $items = $query
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
            )
            ->orderBy('users.name')
            ->orderBy('users.email')
            ->orderBy('users.id')
            ->forPage(
                $parameters['page'],
                $parameters['per_page'],
            )
            ->get()
            ->map(
                fn (object $student): array => $this->serializeStudent($student)
            )
            ->values()
            ->all();

        return $this->page(
            $items,
            $total,
            $parameters,
        );
    }

    private function applyFilters(
        Builder $query,
        array $parameters,
        bool $includeLearnerProfile,
    ): void {
        if ($parameters['status'] !== null) {
            $query->where(
                'users.status',
                $parameters['status'],
            );
        }

        if ($parameters['search'] === null) {
            return;
        }

        $search = $parameters['search'];
        $pattern = '%'
            .$this->escapeLike($search)
            .'%';
        $uuid = Str::isUuid($search)
            ? $search
            : null;

        $query->where(
            function (Builder $searchQuery) use (
                $includeLearnerProfile,
                $pattern,
                $uuid,
            ): void {
                $searchQuery
                    ->whereRaw(
                        'users.name ILIKE ? ESCAPE ?',
                        [$pattern, '\\'],
                    )
                    ->orWhereRaw(
                        'users.email ILIKE ? ESCAPE ?',
                        [$pattern, '\\'],
                    );

                if ($uuid === null) {
                    return;
                }

                $searchQuery->orWhere(
                    'users.id',
                    $uuid,
                );

                if ($includeLearnerProfile) {
                    $searchQuery->orWhere(
                        'learner_profiles.id',
                        $uuid,
                    );
                }
            }
        );
    }

    private function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        );
    }

    private function page(
        array $items,
        int $total,
        array $parameters,
    ): array {
        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $parameters['page'],
                'per_page' => $parameters['per_page'],
                'total' => $total,
                'last_page' => $this->lastPage(
                    $total,
                    $parameters['per_page'],
                ),
            ],
        ];
    }

    private function isPageOutOfRange(
        int $total,
        array $parameters,
    ): bool {
        return $parameters['page'] > $this->lastPage(
            $total,
            $parameters['per_page'],
        );
    }

    private function lastPage(
        int $total,
        int $perPage,
    ): int {
        if ($total === 0) {
            return 1;
        }

        return intdiv($total, $perPage)
            + ($total % $perPage === 0 ? 0 : 1);
    }

    private function serializeTeacher(object $teacher): array
    {
        $tracked =
            $teacher->provisioned_by_user_id
            !== null;

        return [
            'user_id' => $teacher->user_id,
            'name' => $teacher->name,
            'email' => $teacher->email,
            'role' => $teacher->role,
            'status' => $teacher->status,
            'provisioning' => [
                'state' => ! $tracked
                    ? 'untracked'
                    : (
                        $teacher->setup_completed_at
                            === null
                            ? 'pending_setup'
                            : 'completed'
                    ),
                'provisioned_by_user_id' => $teacher->provisioned_by_user_id,
                'setup_completed_at' => $this->iso(
                    $teacher->setup_completed_at
                ),
                'created_at' => $this->iso(
                    $teacher->provisioning_created_at
                ),
            ],
            'assignment_counts' => [
                'active' => (int)
                    $teacher->active_assignment_count,
                'inactive' => (int)
                    $teacher->inactive_assignment_count,
                'total' => (int)
                    $teacher->total_assignment_count,
            ],
            'created_at' => $this->iso(
                $teacher->created_at
            ),
        ];
    }

    private function serializeStudent(object $student): array
    {
        return [
            'user_id' => $student->user_id,
            'learner_profile_id' => $student->learner_profile_id,
            'name' => $student->name,
            'email' => $student->email,
            'role' => $student->role,
            'status' => $student->status,
            'enrollment_counts' => [
                'pending' => (int)
                    $student->pending_enrollment_count,
                'active' => (int)
                    $student->active_enrollment_count,
                'inactive' => (int)
                    $student->inactive_enrollment_count,
                'total' => (int)
                    $student->total_enrollment_count,
            ],
            'created_at' => $this->iso(
                $student->created_at
            ),
            'learner_profile_created_at' => $this->iso(
                $student->learner_profile_created_at
            ),
        ];
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse(
            $value
        )->toISOString();
    }
}
