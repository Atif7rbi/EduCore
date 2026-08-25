<?php

namespace App\Application\Exceptions;

use Throwable;

class IntegrityConstraintViolation extends ApplicationException
{
    public function __construct(
        public readonly string $sqlState,
        string $message = 'A database integrity constraint was violated.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
