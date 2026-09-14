<?php

namespace App\Application\TeacherAssignment;

use Illuminate\Support\Facades\DB;

class LockTeacherSubjectAssignmentOperation
{
    public function acquire(string $operationId): void
    {
        DB::select(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            [$operationId],
        );
    }
}
