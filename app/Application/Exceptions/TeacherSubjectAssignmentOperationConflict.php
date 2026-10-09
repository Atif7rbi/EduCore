<?php

namespace App\Application\Exceptions;

class TeacherSubjectAssignmentOperationConflict extends ApplicationException
{
    public function __construct()
    {
        parent::__construct(
            'TeacherSubjectAssignment operation_id was already used for different canonical facts.'
        );
    }
}
