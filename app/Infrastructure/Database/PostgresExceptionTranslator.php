<?php

namespace App\Infrastructure\Database;

use App\Application\Exceptions\ConcurrencyConflict;
use App\Application\Exceptions\IntegrityConstraintViolation;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

class PostgresExceptionTranslator
{
    public function translate(Throwable $exception): Throwable
    {
        $sqlState = $this->sqlState($exception);

        if (
            ($sqlState !== null && str_starts_with($sqlState, '23'))
            || $sqlState === 'P0001'
        ) {
            return new IntegrityConstraintViolation(
                sqlState: $sqlState,
                previous: $exception,
            );
        }

        if (in_array($sqlState, ['40001', '40P01'], true)) {
            return new ConcurrencyConflict(
                sqlState: $sqlState,
                previous: $exception,
            );
        }

        return $exception;
    }

    private function sqlState(Throwable $exception): ?string
    {
        if (
            ! $exception instanceof QueryException
            && ! $exception instanceof PDOException
        ) {
            return null;
        }

        $errorInfo = $exception->errorInfo;

        if (! is_array($errorInfo)) {
            return null;
        }

        $sqlState = $errorInfo[0] ?? null;

        return is_string($sqlState) && $sqlState !== ''
            ? $sqlState
            : null;
    }
}
