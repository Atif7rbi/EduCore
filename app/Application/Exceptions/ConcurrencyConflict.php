<?php

namespace App\Application\Exceptions;

use Throwable;

class ConcurrencyConflict extends ApplicationException
{
    public function __construct(
        public readonly string $sqlState,
        string $message = 'The operation conflicted with a concurrent transaction.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
