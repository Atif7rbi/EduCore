<?php

namespace App\Application\Exceptions;

class StudentEnrollmentOperationConflict extends ApplicationException
{
    public function __construct()
    {
        parent::__construct(
            'StudentEnrollment operation_id was already used for different canonical facts.'
        );
    }
}
