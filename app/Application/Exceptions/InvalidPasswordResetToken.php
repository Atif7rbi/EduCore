<?php

namespace App\Application\Exceptions;

class InvalidPasswordResetToken extends ApplicationException
{
    public function __construct()
    {
        parent::__construct(
            'The password reset token is invalid or expired.'
        );
    }
}
