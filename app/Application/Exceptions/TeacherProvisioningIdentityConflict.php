<?php

namespace App\Application\Exceptions;

use Throwable;

class TeacherProvisioningIdentityConflict extends ApplicationException
{
    public function __construct(
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'The Teacher email identity already belongs to a User.',
            0,
            $previous,
        );
    }
}
