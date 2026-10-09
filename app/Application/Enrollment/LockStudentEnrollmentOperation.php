<?php

namespace App\Application\Enrollment;

use Illuminate\Support\Facades\DB;

class LockStudentEnrollmentOperation
{
    public function acquire(string $operationId): void
    {
        DB::select(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            [$operationId],
        );
    }
}
