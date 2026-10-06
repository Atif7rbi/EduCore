<?php

namespace App\Application\Exceptions;

class TeacherAuthoringConflict extends ApplicationException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
